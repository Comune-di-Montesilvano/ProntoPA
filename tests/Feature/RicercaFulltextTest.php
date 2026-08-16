<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RicercaFulltextTest extends TestCase
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

    public function test_ricerca_trova_per_parola_nel_testo(): void
    {
        $match = Segnalazione::factory()->create([
            'testo_segnalazione' => 'Infiltrazione di acqua dal soffitto della palestra',
        ]);
        Segnalazione::factory()->create([
            'testo_segnalazione' => 'Cancello esterno bloccato',
        ]);

        $response = $this->actingAs($this->admin())->get(route('gestione.dashboard', ['q' => 'palestra']));

        $response->assertOk();
        $response->assertSee((string) $match->id_segnalazione, escape: false);
        $response->assertDontSee('Cancello esterno', escape: false);
    }

    public function test_ricerca_per_id_funziona_ancora(): void
    {
        $seg = Segnalazione::factory()->create();

        $response = $this->actingAs($this->admin())->get(
            route('gestione.dashboard', ['q' => (string) $seg->id_segnalazione])
        );

        $response->assertOk();
        $response->assertSee((string) $seg->id_segnalazione, escape: false);
    }
}
