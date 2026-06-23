<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class KpiTransizioniTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_statistiche_include_kpi_transizioni(): void
    {
        $gestore = User::factory()->create(['gestore_segnalazioni' => true]);
        $gestore->assignRole('gestore');

        $segnalazione = Segnalazione::factory()->create();

        // Inserisce 2 record storico a distanza fissa
        $t0 = Carbon::now()->subHours(10);
        $t1 = Carbon::now()->subHours(4);
        $t2 = Carbon::now();

        \Illuminate\Support\Facades\DB::table('stati_segnalazioni')->insert([
            ['id_segnalazione' => $segnalazione->id_segnalazione, 'id_stato_segnalazione' => 1, 'id_utente' => $gestore->id, 'data_registrazione' => $t0],
            ['id_segnalazione' => $segnalazione->id_segnalazione, 'id_stato_segnalazione' => 2, 'id_utente' => $gestore->id, 'data_registrazione' => $t1],
            ['id_segnalazione' => $segnalazione->id_segnalazione, 'id_stato_segnalazione' => 3, 'id_utente' => $gestore->id, 'data_registrazione' => $t2],
        ]);

        $response = $this->actingAs($gestore)->get(route('statistiche.index'));

        $response->assertOk();
        // La view deve ricevere kpiTransizioni
        $response->assertViewHas('kpiTransizioni');

        $kpi = $response->viewData('kpiTransizioni');
        // Stato 1 ha passato 6 ore, stato 2 ha passato 4 ore
        $stato1 = $kpi->firstWhere('id_stato', 1);
        $this->assertNotNull($stato1);
        $this->assertEqualsWithDelta(6.0, $stato1['ore_medie'], 0.5);
    }
}
