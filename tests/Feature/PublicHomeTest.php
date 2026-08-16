<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicHomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        Cache::flush();
    }

    public function test_public_home_can_be_rendered(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Accedi');
    }

    public function test_public_home_mostra_tempo_medio_risoluzione(): void
    {
        Segnalazione::factory()->create([
            'data_segnalazione' => now()->subDays(5),
            'data_chiusura'     => now(),
            'flag_riservata'    => false,
            'flag_pubblicata'   => true,
        ]);

        Cache::flush();

        $response = $this->get(route('home'));

        $response->assertOk();
        $this->assertArrayHasKey('tempo_medio_gg', $response->viewData('kpi'));
        $this->assertNotNull($response->viewData('kpi')['tempo_medio_gg']);
    }
}