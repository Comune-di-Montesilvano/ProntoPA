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

    public function test_account_locale_entra_con_email_maiuscole_e_spazi(): void
    {
        $user = User::factory()->create(['email' => 'mario@ditta.it']);

        $this->post('/login', ['username' => '  Mario@Ditta.IT ', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_non_seleziona_mai_un_utente_ldap(): void
    {
        User::factory()->create(['email' => 'stessa@ente.it', 'auth_source' => 'ldap', 'ldap_guid' => 'g-1', 'password' => bcrypt('password')]);

        $this->post('/login', ['username' => 'stessa@ente.it', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_email_con_account_locale_e_ldap_uguali_entra_la_ditta(): void
    {
        User::factory()->create(['email' => 'stessa@ente.it', 'auth_source' => 'ldap', 'ldap_guid' => 'g-1', 'password' => null]);
        $ditta = User::factory()->create(['email' => 'stessa@ente.it']);

        $this->post('/login', ['username' => 'stessa@ente.it', 'password' => 'password']);

        $this->assertAuthenticatedAs($ditta);
    }

    public function test_ad_valido_senza_gruppo_mostra_messaggio_dedicato(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('esterno', ['Domain Users']));

        $response = $this->from('/login')->post('/login', ['username' => 'esterno', 'password' => 'pw']);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username' => 'Non sei abilitato a ProntoPA. Contatta l\'amministratore.']);
    }

    public function test_legacy_con_username_entra_se_ad_non_lo_conosce(): void
    {
        $legacy = User::factory()->create(['username' => 'vecchio.utente']);

        $this->post('/login', ['username' => 'vecchio.utente', 'password' => 'password']);

        $this->assertAuthenticatedAs($legacy);
    }

    public function test_ad_giu_legacy_entra_comunque(): void
    {
        $this->dir->nonDisponibile = true;
        $legacy = User::factory()->create(['username' => 'vecchio.utente']);

        $this->post('/login', ['username' => 'vecchio.utente', 'password' => 'password']);

        $this->assertAuthenticatedAs($legacy);
    }

    public function test_ad_giu_dipendente_vede_non_disponibile(): void
    {
        $this->dir->nonDisponibile = true;

        $response = $this->from('/login')->post('/login', ['username' => 'm.rossi', 'password' => 'pw']);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username' => 'Autenticazione dei dipendenti temporaneamente non disponibile. Riprova più tardi.']);
    }

    public function test_ad_giu_ditta_via_email_entra(): void
    {
        $this->dir->nonDisponibile = true;
        $user = User::factory()->create(['email' => 'mario@ditta.it']);

        $this->post('/login', ['username' => 'mario@ditta.it', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_credenziali_errate_messaggio_generico(): void
    {
        $response = $this->from('/login')->post('/login', ['username' => 'nessuno', 'password' => 'x']);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username' => trans('auth.failed')]);
    }

    public function test_ditta_con_credenziali_valide_va_al_challenge_email(): void
    {
        $ditta = User::factory()->create(['email' => 'mario@ditta.it']);
        $ditta->assignRole('impresa');

        $response = $this->post('/login', ['username' => 'mario@ditta.it', 'password' => 'password']);

        $this->assertGuest();
        $response->assertRedirect(route('two-factor.login'));
        $this->assertSame('email', session('login.metodo'));
        $this->assertSame($ditta->id, session('login.id'));
    }
}
