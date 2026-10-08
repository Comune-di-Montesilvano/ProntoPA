<?php

namespace Tests\Browser;

use App\Models\Delega;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\User;
use App\Services\Deleghe\DelegaService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Richiede OIDC_MOCK=true nell'env del server Dusk (dusk.yml). */
class DelegaScuolaTest extends DuskTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(ImpostazioniSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_richiesta_approvazione_e_segnalazione(): void
    {
        $istituto = Istituto::create(['descrizione' => 'IC Delfico', 'codice_meccanografico' => 'PEIC82600C', 'email' => 'peic82600c@istruzione.it', 'tipo' => 'Scuola', 'tipo_ente' => 'scuola', 'attivo' => true]);
        Plesso::create(['id_istituto' => $istituto->id_istituto, 'nome' => 'Media Delfico', 'codice_meccanografico' => 'PEMM82601D']);
        $user = User::factory()->create([
            'auth_source' => 'spid', 'codice_fiscale' => 'VRDLGU75B02H501K', 'username' => 'VRDLGU75B02H501K',
            'name' => 'Luigi Verdi', 'password' => null, 'email_verified_at' => now(), 'attivo' => true, 'approval_status' => 'approved',
        ]);
        $user->assignRole('segnalatore');

        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->clickLink('Entra con SPID o CIE')
                ->waitForText('Simulatore SPID')
                ->type('codice_fiscale', 'VRDLGU75B02H501K')
                ->type('nome', 'Luigi')
                ->type('cognome', 'Verdi')
                ->press('Entra (simulato)')
                ->waitForText('Le mie deleghe')
                ->type('q', 'Delfico')
                ->press('CERCA')
                ->clickLink('IC Delfico')
                ->waitForText('Media Delfico')
                ->check('tutto')
                ->press('INVIA RICHIESTA')
                ->waitForText('Richiesta inviata');
        });

        $gruppo = Delega::where('user_id', $user->id)->value('gruppo_richiesta');
        $link = app(DelegaService::class)->invia($gruppo, true);

        // la segreteria apre il link da un altro browser, senza login
        $this->browse(function (Browser $delegato, Browser $segreteria) use ($link) {
            $segreteria->visit($link)
                ->waitForText('Richiesta di delega')
                ->press('Approva')
                ->waitForText('approvata');

            $delegato->visit('/segnalazioni/create')
                // la select dei plessi è nascosta finché non si sceglie una tipologia
                ->assertPathIs('/segnalazioni/create')
                ->assertSourceHas('Media Delfico');
        });
    }
}
