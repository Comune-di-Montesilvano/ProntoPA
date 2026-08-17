<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class CreaSegnalazioneTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_crea_una_segnalazione(): void
    {
        $admin = User::factory()->create([
            'username'                 => 'e2e.admin',
            'amministratore'           => true,
            'gestore_segnalazioni'     => true,
            'supervisore_segnalazioni' => true,
        ]);
        $admin->assignRole('admin');

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/segnalazioni/create')
                ->assertSee('Nuova segnalazione')
                ->press('IMPIANTO ELETTRICO')
                ->select('id_provenienza', '1')
                ->type('testo_segnalazione', 'Lampione spento in via Roma, test E2E Dusk.')
                ->press('Invia segnalazione')
                ->waitForLocation('/segnalazioni')
                ->assertSee('Lampione spento in via Roma, test E2E Dusk.');
        });
    }
}
