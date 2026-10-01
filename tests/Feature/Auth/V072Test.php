<?php

namespace Tests\Feature\Auth;

use App\Services\Auth\MappaGruppiLdap;
use App\Services\Directory\Directory;
use Database\Seeders\ImpostazioniSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

/** v0.7.2: gruppi AD da env, diagnosi ldap:prova, messaggi di login in italiano. */
class V072Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_credenziali_errate_in_italiano(): void
    {
        $this->from('/login')->post('/login', ['username' => 'nessuno', 'password' => 'x'])
            ->assertSessionHasErrors(['username' => 'Credenziali non valide.']);
    }

    public function test_troppi_tentativi_in_italiano(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'nessuno', 'password' => 'x']);
        }

        $errore = $this->from('/login')->post('/login', ['username' => 'nessuno', 'password' => 'x'])
            ->getSession()->get('errors')->first('username');

        $this->assertStringStartsWith('Troppi tentativi di accesso', $errore);
    }

    public function test_nomi_gruppi_ad_da_configurazione_env(): void
    {
        config(['ldap.gruppi.gestori' => 'GG_MANUTENZIONE_GESTORI']);

        $this->assertSame('gestore', app(MappaGruppiLdap::class)->risolvi(['GG_MANUTENZIONE_GESTORI'])->ruolo);
        $this->assertNull(app(MappaGruppiLdap::class)->risolvi(['PRONTOPA_GESTORI']));
    }

    public function test_gruppi_ad_non_sono_piu_impostazioni(): void
    {
        $this->seed(ImpostazioniSeeder::class);

        $this->assertSame(0, DB::table('impostazioni')->where('chiave', 'like', 'ldap_gruppo_%')->count());
    }

    public function test_ldap_prova_mostra_configurazione_e_host_vuoto(): void
    {
        config(['ldap.host' => '', 'ldap.user_dn_template' => '%s@ente.local', 'ldap.base_dn' => 'DC=ente,DC=local']);

        $this->artisan('ldap:prova', ['username' => 'm.rossi'])
            ->expectsQuestion('Password', 'pw')
            ->expectsOutputToContain('LDAP_HOST non configurato')
            ->expectsOutputToContain('%s@ente.local')
            ->assertFailed();
    }

    public function test_ldap_prova_spiega_il_motivo_del_rifiuto(): void
    {
        $dir = new FakeDirectory();
        $dir->motivo = 'bind riuscito ma nessun utente con userprincipalname=m.rossi@ente.local sotto DC=ente,DC=local';
        $this->app->instance(Directory::class, $dir);

        $this->artisan('ldap:prova', ['username' => 'm.rossi'])
            ->expectsQuestion('Password', 'pw')
            ->expectsOutputToContain('nessun utente con userprincipalname=m.rossi@ente.local')
            ->assertFailed();
    }
}
