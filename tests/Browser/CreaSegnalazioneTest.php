<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class CreaSegnalazioneTest extends DuskTestCase
{
    use DatabaseTruncation;

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
        $ente = \App\Models\Istituto::create(['descrizione' => 'IC E2E', 'id_categoria' => \App\Models\CategoriaEnte::factory()->create()->id_categoria, 'attivo' => true]);
        \App\Models\Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => 'Sede E2E', 'indirizzo' => 'Via Roma 1']);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/segnalazioni/create')
                ->assertSee('Nuova segnalazione')
                ->assertSee('Via Roma 1')
                ->press('IMPIANTO ELETTRICO')
                ->type('titolo', 'Lampione spento via Roma')
                ->type('testo_segnalazione', 'Lampione spento in via Roma, test E2E Dusk.')
                ->waitFor('@submit-segnalazione')
                ->press('@submit-segnalazione')
                ->waitForLocation('/segnalazioni')
                ->assertSee('Lampione spento via Roma');
        });
    }
}
