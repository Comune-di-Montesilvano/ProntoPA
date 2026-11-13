<?php

namespace Tests\Feature\Api;

use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 1.0.0: via API la sede è obbligatoria (ente attivo) e i campi tolti sono ignorati. */
class SegnalazioneApiTest extends TestCase
{
    use RefreshDatabase;

    private Plesso $sede;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, RolesAndPermissionsSeeder::class]);
        $ente = Istituto::create(['descrizione' => 'IC Api', 'id_categoria' => \App\Models\CategoriaEnte::factory()->create()->id_categoria, 'attivo' => true]);
        $this->sede = Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => 'Sede API']);
        $this->actingAs(User::factory()->create());
    }

    public function test_api_sede_obbligatoria_422(): void
    {
        $this->postJson('/api/segnalazioni', ['id_tipologia_segnalazione' => 1, 'testo_segnalazione' => 'Buca'])
            ->assertStatus(422)->assertJsonValidationErrors('id_plesso');

        $spento = Istituto::create(['descrizione' => 'Spento', 'id_categoria' => $this->sede->istituto->id_categoria, 'attivo' => false]);
        $sedeSpenta = Plesso::create(['id_istituto' => $spento->id_istituto, 'nome' => 'Sede spenta']);
        $this->postJson('/api/segnalazioni', ['id_tipologia_segnalazione' => 1, 'testo_segnalazione' => 'Buca', 'id_plesso' => $sedeSpenta->id_plesso])
            ->assertStatus(422)->assertJsonValidationErrors('id_plesso');

        $this->assertSame(0, Segnalazione::count());
    }

    public function test_api_ignora_campi_tolti(): void
    {
        $this->postJson('/api/segnalazioni', [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione' => 'Buca davanti al cancello',
            'id_plesso' => $this->sede->id_plesso,
            'id_provenienza' => 1,
            'segnalante' => 'Portale',
            'latitudine' => 42.5,
            'longitudine' => 14.1,
            'id_specializzazione' => 2,
        ])->assertCreated();

        $this->assertSame($this->sede->id_plesso, (int) Segnalazione::firstOrFail()->id_plesso);
    }
}
