<?php

namespace Tests\Feature;

use App\Jobs\InviaWebhookOutbound;
use App\Models\Azione;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** "Assegna a me" o riassegnazioni che non cambiano nulla non scrivono storico né avvisano. */
class PassaggiAVuotoTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private Segnalazione $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $this->s = Segnalazione::factory()->create([
            'id_stato_segnalazione' => 2,
            'id_operatore_assegnato' => $this->gestore->id,
        ]);
    }

    private function esegui(string $codice, array $params = []): void
    {
        app(SegnalazioneWorkflowService::class)->eseguiAzione(
            $this->s->fresh(), Azione::where('codice', $codice)->value('id_azione'), $this->gestore, $params,
        );
    }

    public function test_assegna_a_me_gia_mia_non_scrive_storico(): void
    {
        Notification::fake();
        Queue::fake();
        $prima = StoricoStatoSegnalazione::count();

        $this->esegui('prendi_in_carico');

        $this->assertSame($prima, StoricoStatoSegnalazione::count());
        Notification::assertNothingSent();
        Queue::assertNotPushed(InviaWebhookOutbound::class);
    }

    public function test_assegna_a_me_gia_mia_con_nota_salva_la_nota(): void
    {
        Queue::fake();

        $this->esegui('prendi_in_carico', ['nota' => 'Ci penso io']);

        $this->assertTrue(NotaSegnalazione::where('testo', 'Ci penso io')->exists());
    }

    public function test_riassegna_ad_altro_operaio_scrive_storico(): void
    {
        Queue::fake();
        Notification::fake();
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');
        $this->s->update(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => User::factory()->create()->id]);
        $prima = StoricoStatoSegnalazione::count();

        $this->esegui('assegna_operatore', ['id_operatore' => $operaio->id]);

        $this->assertSame($prima + 1, StoricoStatoSegnalazione::count());
    }

    public function test_pannello_assegna_non_propone_a_me_se_gia_mia(): void
    {
        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertDontSee('dusk="assegna-me"', false);
    }
}
