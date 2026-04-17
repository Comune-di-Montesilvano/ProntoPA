<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoPublishSegnalazioniTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_auto_publish_command_publishes_matching_segnalazioni(): void
    {
        Impostazione::set('publication_enabled', true);
        Impostazione::set('publication_auto_state_id', 2);

        Segnalazione::factory(3)->create([
            'id_stato_segnalazione' => 2,
            'flag_pubblicata' => false,
            'flag_riservata' => false,
        ]);

        Segnalazione::factory()->create([
            'id_stato_segnalazione' => 1,
            'flag_pubblicata' => false,
        ]);

        $this->artisan('app:auto-publish-segnalazioni')
            ->assertExitCode(0);

        $this->assertSame(3, Segnalazione::where('id_stato_segnalazione', '>=', 2)
            ->where('flag_pubblicata', true)
            ->count());
    }

    public function test_auto_publish_command_does_nothing_when_disabled(): void
    {
        Impostazione::set('publication_enabled', false);
        Impostazione::set('publication_auto_state_id', 2);

        Segnalazione::factory()->create([
            'id_stato_segnalazione' => 2,
            'flag_pubblicata' => false,
        ]);

        $this->artisan('app:auto-publish-segnalazioni')
            ->assertExitCode(0);

        $this->assertSame(0, Segnalazione::where('flag_pubblicata', true)->count());
    }

    public function test_toggle_riservata_from_gestione_dashboard(): void
    {
        $admin = User::factory()->create([
            'amministratore' => true,
            'gestore_segnalazioni' => true,
            'supervisore_segnalazioni' => true,
        ]);
        $admin->assignRole('admin');

        $seg = Segnalazione::factory()->create([
            'flag_riservata' => false,
            'flag_pubblicata' => true,
        ]);

        $response = $this->actingAs($admin)
            ->patch(route('segnalazioni.toggle-riservata', $seg));

        $response->assertRedirect();

        $seg->refresh();
        $this->assertTrue($seg->flag_riservata);

        $response = $this->actingAs($admin)
            ->patch(route('segnalazioni.toggle-riservata', $seg));

        $seg->refresh();
        $this->assertFalse($seg->flag_riservata);
    }

    public function test_scope_pubbliche_excludes_riservate(): void
    {
        Segnalazione::factory()
            ->create(['flag_pubblicata' => true, 'flag_riservata' => false]);

        Segnalazione::factory()
            ->create(['flag_pubblicata' => true, 'flag_riservata' => true]);

        $this->assertSame(1, Segnalazione::pubbliche()->count());
    }
}
