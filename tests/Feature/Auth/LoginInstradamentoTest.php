<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Directory\Directory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

class LoginInstradamentoTest extends TestCase
{
    use RefreshDatabase;

    private FakeDirectory $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['ldap.user_dn_template' => '%s@ente.local']);
        $this->dir = new FakeDirectory();
        $this->app->instance(Directory::class, $this->dir);
    }

    public function test_dipendente_entra_con_username_ad(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_GESTORI']));

        $response = $this->post('/login', ['username' => 'm.rossi', 'password' => 'pw']);

        $this->assertAuthenticated();
        $this->assertSame('ldap', auth()->user()->auth_source);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_dipendente_entra_con_upn_o_dominio_barra(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_OPERAI']));

        $this->post('/login', ['username' => 'M.Rossi@ente.local', 'password' => 'pw']);
        $this->assertAuthenticated();

        auth()->logout();

        $this->post('/login', ['username' => 'ENTE\\m.rossi', 'password' => 'pw']);
        $this->assertAuthenticated();
    }

    public function test_ad_valido_senza_gruppo_mostra_messaggio_dedicato(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('esterno', ['Domain Users']));

        $response = $this->from('/login')->post('/login', ['username' => 'esterno', 'password' => 'pw']);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username' => 'Non sei abilitato a ProntoPA. Contatta l\'amministratore.']);
    }

    public function test_ad_giu_dipendente_vede_non_disponibile(): void
    {
        $this->dir->nonDisponibile = true;

        $response = $this->from('/login')->post('/login', ['username' => 'm.rossi', 'password' => 'pw']);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username' => 'Autenticazione dei dipendenti temporaneamente non disponibile. Riprova più tardi.']);
    }

    public function test_credenziali_errate_messaggio_generico(): void
    {
        $response = $this->from('/login')->post('/login', ['username' => 'nessuno', 'password' => 'x']);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username' => trans('auth.failed')]);
    }
}
