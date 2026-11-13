<?php

namespace Tests\Feature\Api;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SegnalazioneApiDedupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        \Illuminate\Support\Facades\Cache::flush();
        Impostazione::set('adesioni_enabled', true);

        $istituto = \App\Models\Istituto::create(['descrizione' => 'IC Prova', 'id_categoria' => \App\Models\CategoriaEnte::factory()->create()->id_categoria]);
        $this->idPlesso = \App\Models\Plesso::create(['id_istituto' => $istituto->id_istituto, 'nome' => 'Sede'])->id_plesso;

        $this->actingAs(User::factory()->create());
    }

    private int $idPlesso;

    public function test_api_409_se_esiste_simile(): void
    {
        Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => $this->idPlesso,
        ]);

        $response = $this->postJson('/api/segnalazioni', [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Buca in strada',
            'id_provenienza'            => 1,
            'id_plesso'                 => $this->idPlesso,
            'segnalante'                => 'Portale Cittadino',
        ]);

        $response->assertStatus(409);
        $response->assertJsonStructure(['simili' => [['id', 'stato', 'data']]]);
        $this->assertSame(1, Segnalazione::count());
    }

    public function test_api_force_crea_comunque(): void
    {
        Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => $this->idPlesso,
        ]);

        $response = $this->postJson('/api/segnalazioni', [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Buca in strada',
            'id_provenienza'            => 1,
            'id_plesso'                 => $this->idPlesso,
            'segnalante'                => 'Portale Cittadino',
            'force'                     => true,
        ]);

        $response->assertCreated();
        $this->assertSame(2, Segnalazione::count());
    }

    public function test_api_flag_spento_comportamento_attuale(): void
    {
        Impostazione::set('adesioni_enabled', false);

        Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => $this->idPlesso,
        ]);

        $response = $this->postJson('/api/segnalazioni', [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Buca in strada',
            'id_provenienza'            => 1,
            'id_plesso'                 => $this->idPlesso,
            'segnalante'                => 'Portale Cittadino',
        ]);

        $response->assertCreated();
        $this->assertSame(2, Segnalazione::count());
    }
}
