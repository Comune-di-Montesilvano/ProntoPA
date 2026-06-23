<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportXlsxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function gestore(): User
    {
        $user = User::factory()->create(['supervisore_segnalazioni' => true]);
        $user->assignRole('gestore');
        return $user;
    }

    public function test_export_xlsx_mensile_gestore(): void
    {
        Segnalazione::factory()->create(['data_segnalazione' => now()]);

        $response = $this->actingAs($this->gestore())->get(
            route('gestione.reports.mensile.xlsx', ['mese' => now()->month, 'anno' => now()->year])
        );

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }

    public function test_export_xlsx_riepilogo_impresa(): void
    {
        $impresa = \App\Models\Impresa::factory()->create();
        $user = User::factory()->create(['id_impresa' => $impresa->id_impresa]);
        $user->assignRole('impresa');

        $response = $this->actingAs($user)->get(
            route('imprese.reports.riepilogo.xlsx')
        );

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }
}
