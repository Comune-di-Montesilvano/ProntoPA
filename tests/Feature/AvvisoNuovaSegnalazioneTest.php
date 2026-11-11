<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\NuovaSegnalazioneNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Email ai gestori della tipologia per ogni nuova segnalazione (o riclassificata). */
class AvvisoNuovaSegnalazioneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
    }

    /** @param list<int> $tipologie */
    private function gestore(array $tipologie = [], bool $tutte = false, array $attributi = []): User
    {
        $u = User::factory()->create(array_merge([
            'attivo' => true, 'gestore_segnalazioni' => true, 'gestore_tutte_tipologie' => $tutte,
        ], $attributi));
        $u->assignRole('gestore');
        $u->tipologieGestite()->sync($tipologie);

        return $u;
    }

    private function supervisore(array $attributi = []): User
    {
        $u = User::factory()->create(array_merge(['attivo' => true, 'gestore_segnalazioni' => true, 'supervisore_segnalazioni' => true], $attributi));
        $u->assignRole('gestore');

        return $u;
    }

    public function test_nuova_avvisa_gestori_della_tipologia(): void
    {
        $a = $this->gestore([1]);
        $b = $this->gestore([3]);
        $c = $this->gestore([], tutte: true);
        $sup = $this->supervisore();

        $s = Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1]);

        Notification::assertSentTo([$a, $c], NuovaSegnalazioneNotification::class,
            fn (NuovaSegnalazioneNotification $n) => $n->segnalazione->is($s) && ! $n->riclassificata);
        Notification::assertNotSentTo([$b, $sup], NuovaSegnalazioneNotification::class);
    }

    public function test_admin_con_tipologia_avvisato(): void
    {
        $admin = User::factory()->create(['attivo' => true, 'amministratore' => true]);
        $admin->assignRole('admin');
        $admin->tipologieGestite()->sync([5]);
        $altroAdmin = User::factory()->create(['attivo' => true, 'amministratore' => true]);
        $altroAdmin->assignRole('admin');

        Segnalazione::factory()->create(['id_tipologia_segnalazione' => 5]);

        Notification::assertSentTo($admin, NuovaSegnalazioneNotification::class);
        Notification::assertNotSentTo($altroAdmin, NuovaSegnalazioneNotification::class);
    }

    public function test_nuova_orfana_avvisa_supervisori(): void
    {
        $this->gestore([3]);
        $sup = $this->supervisore();

        Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1]);

        Notification::assertSentTo($sup, NuovaSegnalazioneNotification::class);
    }

    public function test_chi_crea_non_riceve(): void
    {
        $a = $this->gestore([1]);
        $b = $this->gestore([1]);

        $this->actingAs($a);
        Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1]);

        Notification::assertNotSentTo($a, NuovaSegnalazioneNotification::class);
        Notification::assertSentTo($b, NuovaSegnalazioneNotification::class);
    }

    public function test_disattivati_esclusi(): void
    {
        $spento = $this->gestore([1], attributi: ['attivo' => false]);
        $supSpento = $this->supervisore(['attivo' => false]);

        Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1]);

        Notification::assertNothingSent();
        $this->assertNotNull($spento);
        $this->assertNotNull($supSpento);
    }

    public function test_riclassificazione_avvisa_nuova_tipologia(): void
    {
        $a = $this->gestore([1]);
        $b = $this->gestore([3]);
        $s = Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1]);

        $s->update(['id_tipologia_segnalazione' => 3]);

        Notification::assertSentTo($b, NuovaSegnalazioneNotification::class, fn ($n) => $n->riclassificata);
        Notification::assertSentToTimes($a, NuovaSegnalazioneNotification::class, 1);
    }

    public function test_update_senza_cambio_tipologia_nessun_avviso(): void
    {
        $s = Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1]);
        $a = $this->gestore([1]);

        $s->update(['livello_priorita' => 4]);
        $s->update(['id_tipologia_segnalazione' => 1]);

        Notification::assertNotSentTo($a, NuovaSegnalazioneNotification::class);
    }

    public function test_creazione_via_api_avvisa(): void
    {
        $a = $this->gestore([1]);
        $segnalatore = User::factory()->create(['attivo' => true]);
        $segnalatore->assignRole('segnalatore');

        $this->actingAs($segnalatore)->postJson('/api/segnalazioni', [
            'id_tipologia_segnalazione' => 1, 'testo_segnalazione' => 'Perdita acqua', 'id_provenienza' => 1, 'force' => true,
        ])->assertCreated();

        Notification::assertSentTo($a, NuovaSegnalazioneNotification::class);
    }

    public function test_demo_non_invia_email(): void
    {
        $this->gestore([], tutte: true);

        $this->artisan('demo')->assertSuccessful();

        Notification::assertNotSentTo(User::all(), NuovaSegnalazioneNotification::class);
        $this->assertGreaterThan(0, Segnalazione::count());
        $this->assertFalse(Segnalazione::$silenziaAvvisi);
    }

    public function test_mail_oggetto_e_link(): void
    {
        $s = Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1, 'titolo' => 'Lampione spento']);
        $mail = (new NuovaSegnalazioneNotification($s))->toMail($this->gestore([1]));

        $this->assertStringContainsString('Nuova segnalazione #'.$s->id_segnalazione, $mail->subject);
        $this->assertSame(route('segnalazioni.show', $s->id_segnalazione), $mail->actionUrl);
        $this->assertStringContainsString('riclassificata', (new NuovaSegnalazioneNotification($s, true))->toMail($this->gestore([1]))->subject);
    }
}
