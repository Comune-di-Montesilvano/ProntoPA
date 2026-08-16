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

        $this->actingAs(User::factory()->create());
    }

    public function test_api_409_se_esiste_simile(): void
    {
        Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'latitudine'                => 42.5145,
            'longitudine'               => 14.1500,
        ]);

        $response = $this->postJson('/api/segnalazioni', [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Buca in strada',
            'id_provenienza'            => 1,
            'latitudine'                => 42.5146,
            'longitudine'               => 14.1501,
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
            'latitudine'                => 42.5145,
            'longitudine'               => 14.1500,
        ]);

        $response = $this->postJson('/api/segnalazioni', [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Buca in strada',
            'id_provenienza'            => 1,
            'latitudine'                => 42.5146,
            'longitudine'               => 14.1501,
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
            'latitudine'                => 42.5145,
            'longitudine'               => 14.1500,
        ]);

        $response = $this->postJson('/api/segnalazioni', [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Buca in strada',
            'id_provenienza'            => 1,
            'latitudine'                => 42.5146,
            'longitudine'               => 14.1501,
            'segnalante'                => 'Portale Cittadino',
        ]);

        $response->assertCreated();
        $this->assertSame(2, Segnalazione::count());
    }
}
