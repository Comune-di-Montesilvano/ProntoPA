<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DedupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        \Illuminate\Support\Facades\Cache::flush();
        Impostazione::set('adesioni_enabled', true);
    }

    private function utente(): User
    {
        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        return $user;
    }

    public function test_trova_simile_per_stesso_plesso(): void
    {
        $aperta = Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => 5,
        ]);
        Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => 6,
        ]);
        Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => 5,
            'data_chiusura'             => now(),
        ]);

        $response = $this->actingAs($this->utente())->getJson(
            route('segnalazioni.simili', [
                'id_tipologia_segnalazione' => 1,
                'id_plesso'                 => 5,
            ])
        );

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['id' => $aperta->id_segnalazione]);
    }

    public function test_trova_simile_per_vicinanza_geografica(): void
    {
        $vicina = Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 2,
            'latitudine'                => 42.5145,
            'longitudine'               => 14.1500,
        ]);
        Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 2,
            'latitudine'                => 42.5600,
            'longitudine'               => 14.1500,
        ]);

        $response = $this->actingAs($this->utente())->getJson(
            route('segnalazioni.simili', [
                'id_tipologia_segnalazione' => 2,
                'latitudine'                => 42.5146,
                'longitudine'               => 14.1501,
            ])
        );

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['id' => $vicina->id_segnalazione]);
    }

    public function test_flag_spento_restituisce_lista_vuota(): void
    {
        Impostazione::set('adesioni_enabled', false);

        Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => 5,
        ]);

        $response = $this->actingAs($this->utente())->getJson(
            route('segnalazioni.simili', [
                'id_tipologia_segnalazione' => 1,
                'id_plesso'                 => 5,
            ])
        );

        $response->assertOk();
        $response->assertJsonCount(0);
    }

    public function test_senza_luogo_restituisce_lista_vuota(): void
    {
        Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1]);

        $response = $this->actingAs($this->utente())->getJson(
            route('segnalazioni.simili', ['id_tipologia_segnalazione' => 1])
        );

        $response->assertOk();
        $response->assertJsonCount(0);
    }
}
