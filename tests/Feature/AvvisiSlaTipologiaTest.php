<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\SlaViolazioneNotification;
use App\Notifications\SlaWarningNotification;
use App\Services\SlaService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\TestCase;

/** sla:check avvisa per tipologia: scaduto a gestori + supervisori, a rischio ai soli gestori. Admin puri esclusi. */
class AvvisiSlaTipologiaTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore1;

    private User $gestore3;

    private User $supervisore;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();

        $this->gestore1 = $this->gestore([1]);
        $this->gestore3 = $this->gestore([3]);
        $this->supervisore = User::factory()->create(['attivo' => true, 'gestore_segnalazioni' => true, 'supervisore_segnalazioni' => true]);
        $this->supervisore->assignRole('gestore');
        $this->admin = User::factory()->create(['attivo' => true, 'amministratore' => true]);
        $this->admin->assignRole('admin');
    }

    /** @param list<int> $tipologie */
    private function gestore(array $tipologie): User
    {
        $u = User::factory()->create(['attivo' => true, 'gestore_segnalazioni' => true]);
        $u->assignRole('gestore');
        $u->tipologieGestite()->sync($tipologie);

        return $u;
    }

    private function segnalazione(int $tipologia, int $priorita = 2): Segnalazione
    {
        return Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => $tipologia,
            'livello_priorita' => $priorita,
            'data_scadenza_sla' => now()->subHour(),
        ]);
    }

    private function aRischio(): void
    {
        $this->mock(SlaService::class, function (MockInterface $m) {
            $m->shouldReceive('isViolato')->andReturn(false);
            $m->shouldReceive('isArischio')->andReturn(true);
        });
    }

    public function test_sla_scaduto_a_gestori_tipologia_e_supervisori_non_admin(): void
    {
        $this->segnalazione(1);

        $this->artisan('sla:check')->assertSuccessful();

        Notification::assertSentTo([$this->gestore1, $this->supervisore], SlaViolazioneNotification::class);
        Notification::assertNotSentTo([$this->gestore3, $this->admin], SlaViolazioneNotification::class);
    }

    public function test_sla_scaduto_immediato_anche_con_digest(): void
    {
        Impostazione::set('digest_enabled', true);
        $this->segnalazione(1, priorita: 2);

        $this->artisan('sla:check')->assertSuccessful();

        Notification::assertSentTo($this->gestore1, SlaViolazioneNotification::class);
    }

    public function test_sla_a_rischio_solo_gestori_tipologia(): void
    {
        $this->aRischio();
        $this->segnalazione(1);

        $this->artisan('sla:check')->assertSuccessful();

        Notification::assertSentTo($this->gestore1, SlaWarningNotification::class);
        Notification::assertNotSentTo([$this->supervisore, $this->admin, $this->gestore3], SlaWarningNotification::class);
    }

    public function test_sla_a_rischio_orfana_ai_supervisori(): void
    {
        $this->aRischio();
        $this->segnalazione(5);

        $this->artisan('sla:check')->assertSuccessful();

        Notification::assertSentTo($this->supervisore, SlaWarningNotification::class);
        Notification::assertNotSentTo($this->admin, SlaWarningNotification::class);
    }

    public function test_scaduto_senza_supervisori_ripiega_sugli_admin(): void
    {
        $this->supervisore->update(['attivo' => false]);
        $this->segnalazione(5);

        $this->artisan('sla:check')->assertSuccessful();

        Notification::assertSentTo($this->admin, SlaViolazioneNotification::class);
    }

    public function test_supervisore_disattivato_non_riceve(): void
    {
        $this->supervisore->update(['attivo' => false]);
        $this->segnalazione(1);

        $this->artisan('sla:check')->assertSuccessful();

        Notification::assertNotSentTo($this->supervisore, SlaViolazioneNotification::class);
        Notification::assertSentTo($this->gestore1, SlaViolazioneNotification::class);
    }
}
