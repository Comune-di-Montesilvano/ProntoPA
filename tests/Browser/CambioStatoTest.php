<?php

namespace Tests\Browser;

use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class CambioStatoTest extends DuskTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_gestore_prende_in_carico_una_segnalazione_nuova(): void
    {
        $gestore = User::factory()->create([
            'username'                 => 'e2e.gestore',
            'gestore_segnalazioni'     => true,
            'supervisore_segnalazioni' => true,
        ]);
        $gestore->assignRole('gestore');

        $segnalazione = Segnalazione::factory()->create([
            'id_stato_segnalazione' => 1, // Nuova
        ]);

        $this->browse(function (Browser $browser) use ($gestore, $segnalazione) {
            $browser->loginAs($gestore)
                ->visit('/segnalazioni/' . $segnalazione->id_segnalazione)
                ->assertSee('Nuova')
                ->press('Gestione Segnalazione')
                ->waitFor('#gestione')
                ->select('id_azione', '1') // Prendi in carico
                ->waitFor('@esegui-azione')
                ->press('@esegui-azione')
                ->waitForText('In carico');
        });
    }
}
