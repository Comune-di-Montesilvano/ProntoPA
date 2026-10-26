<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Niente più flag urgente: per i pericoli immediati il 112, nel form il tempo previsto. */
class UrgenteRimossoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
    }

    private function segnalatore(): User
    {
        $u = User::factory()->create();
        $u->assignRole('segnalatore');

        return $u;
    }

    public function test_form_mostra_112_e_tempo_previsto_senza_urgente(): void
    {
        SlaConfigurazione::create(['livello_priorita' => 4, 'ore_target' => 4, 'ore_warning' => 1]);
        SlaConfigurazione::create(['id_tipologia_segnalazione' => 1, 'livello_priorita' => 3, 'ore_target' => 1, 'unita' => 'giorni', 'ore_warning' => 4]);

        $this->actingAs($this->segnalatore())->get(route('segnalazioni.create'))
            ->assertOk()
            ->assertDontSee('segnalazione_urgente')
            ->assertSee('chiama il 112')
            ->assertSee("La priorità definitiva la stabilisce l'ufficio", false)
            ->assertSee('4 ore lavorative')
            ->assertSee('1 giorno lavorativo');
    }

    public function test_store_ignora_urgente(): void
    {
        $this->actingAs($this->segnalatore())->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Perdita acqua',
            'id_provenienza'            => 1,
            'segnalazione_urgente'      => 1,
        ])->assertRedirect();

        $s = Segnalazione::latest('id_segnalazione')->firstOrFail();
        $this->assertFalse((bool) $s->segnalazione_urgente);
        $this->assertSame(2, (int) $s->livello_priorita);
    }

    public function test_migrazione_porta_le_urgenti_aperte_a_critica(): void
    {
        $aperta = Segnalazione::factory()->create(['livello_priorita' => 2, 'segnalazione_urgente' => true, 'id_stato_segnalazione' => 2]);
        $chiusa = Segnalazione::factory()->create(['livello_priorita' => 2, 'segnalazione_urgente' => true, 'id_stato_segnalazione' => 7, 'data_chiusura' => now()]);
        SlaConfigurazione::create(['livello_priorita' => 4, 'ore_target' => 4, 'ore_warning' => 1]);

        $migrazione = require database_path('migrations/2026_10_26_000002_urgenti_aperte_a_critica.php');
        $migrazione->up();

        $this->assertSame(4, (int) $aperta->fresh()->livello_priorita);
        $this->assertNotNull($aperta->fresh()->data_scadenza_sla);
        $this->assertSame(2, (int) $chiusa->fresh()->livello_priorita);
    }

    public function test_codice_non_usa_piu_il_campo(): void
    {
        $trovati = [];
        foreach ([app_path(), resource_path()] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if (str_contains($file->getContents(), 'segnalazione_urgente')) {
                    $trovati[] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $trovati);
    }
}
