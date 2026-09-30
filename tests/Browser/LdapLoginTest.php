<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Richiede LDAP_HOST=mock e LDAP_USER_DN_TEMPLATE=%s@ente.local nell'env del
 * server Dusk (vedi .github/workflows/dusk.yml).
 */
class LdapLoginTest extends DuskTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(ImpostazioniSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_dipendente_ad_entra_e_riceve_il_ruolo_dal_gruppo(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('username', 'mock.gestore')
                ->type('password', 'mock.gestore')
                ->press('Accedi')
                ->waitUntilMissing('#password')
                ->assertPathIsNot('/login');
        });

        $user = User::where('username', 'mock.gestore')->firstOrFail();
        $this->assertSame('ldap', $user->auth_source);
        $this->assertTrue($user->hasRole('gestore'));
    }

    public function test_dipendente_con_upn_entra(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('username', 'mock.operaio@ente.local')
                ->type('password', 'mock.operaio')
                ->press('Accedi')
                ->waitUntilMissing('#password')
                ->assertPathIsNot('/login');
        });
    }

    public function test_utente_ad_senza_gruppo_resta_sul_login_con_messaggio(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('username', 'mock.nessungruppo')
                ->type('password', 'mock.nessungruppo')
                ->press('Accedi')
                ->waitForText('Non sei abilitato a ProntoPA')
                ->assertPathIs('/login');
        });
    }
}
