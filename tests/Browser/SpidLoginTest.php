<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Richiede OIDC_MOCK=true nell'env del server Dusk (dusk.yml). */
class SpidLoginTest extends DuskTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(ImpostazioniSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_primo_accesso_spid_fino_alla_verifica_email(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->clickLink('Entra con SPID o CIE')
                ->waitForText('Simulatore SPID')
                ->type('codice_fiscale', 'VRDLGU75B02H501K')
                ->type('nome', 'Luigi')
                ->type('cognome', 'Verdi')
                ->press('Entra (simulato)')
                ->waitForText('Completa il profilo')
                ->type('email', 'luigi.verdi@example.it')
                ->press('Continua')
                ->waitForLocation('/verify-email');
        });

        $user = User::where('codice_fiscale', 'VRDLGU75B02H501K')->firstOrFail();
        $this->assertSame('spid', $user->auth_source);
        $this->assertNull($user->email_verified_at);
    }
}
