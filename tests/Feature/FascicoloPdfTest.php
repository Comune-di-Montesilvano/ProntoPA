<?php

namespace Tests\Feature;

use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FascicoloPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_gestore_scarica_fascicolo_pdf(): void
    {
        $gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $gestore->assignRole('gestore');

        $segnalazione = Segnalazione::factory()->create();

        $response = $this->actingAs($gestore)->get(
            route('segnalazioni.fascicolo-pdf', $segnalazione)
        );

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_segnalatore_estraneo_non_scarica_fascicolo(): void
    {
        $estraneo = User::factory()->create();
        $estraneo->assignRole('segnalatore');

        $segnalazione = Segnalazione::factory()->create();

        $response = $this->actingAs($estraneo)->get(
            route('segnalazioni.fascicolo-pdf', $segnalazione)
        );

        $response->assertForbidden();
    }
}
