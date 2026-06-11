<?php

namespace Tests\Feature;

use App\Models\Azione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AzioniRapideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create([
            'amministratore' => true,
            'gestore_segnalazioni' => true,
            'supervisore_segnalazioni' => true,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_azioni_rapide_escludono_quelle_con_parametri(): void
    {
        $admin = $this->admin();
        $segnalazione = Segnalazione::factory()->create();

        $workflow = app(SegnalazioneWorkflowService::class);
        $rapide   = $workflow->getAzioniRapide($segnalazione, $admin);

        $this->assertNotEmpty($rapide);
        foreach ($rapide as $azione) {
            $this->assertFalse((bool) $azione->flag_operatore, "Azione {$azione->id_azione} richiede operatore");
            $this->assertFalse((bool) $azione->flag_appalto, "Azione {$azione->id_azione} richiede appalto");
        }
    }

    public function test_azioni_rapide_vuote_su_chiusa(): void
    {
        $admin = $this->admin();
        $segnalazione = Segnalazione::factory()->create(['data_chiusura' => now()]);

        $rapide = app(SegnalazioneWorkflowService::class)->getAzioniRapide($segnalazione, $admin);

        $this->assertTrue($rapide->isEmpty());
    }

    public function test_dashboard_mostra_menu_azioni_rapide(): void
    {
        $admin = $this->admin();
        Segnalazione::factory()->create();

        $response = $this->actingAs($admin)->get(route('gestione.dashboard'));

        $response->assertOk();
        $response->assertSee('Azioni rapide', escape: false);
    }
}
