<?php

namespace Tests\Feature\Auth;

use App\Models\Impresa;
use App\Models\User;
use App\Services\Directory\Directory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

/** v0.8.19: /login è solo per il personale AD, nessun account locale entra più. */
class LoginSoloAdTest extends TestCase
{
    use RefreshDatabase;

    private FakeDirectory $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['ldap.user_dn_template' => '%s@ente.local']);
        $this->dir = new FakeDirectory();
        $this->app->instance(Directory::class, $this->dir);
    }

    public function test_account_locale_attivo_con_password_giusta_non_entra(): void
    {
        $locale = User::factory()->create(['username' => 'ditta.vecchia', 'email' => 'info@ditta.it', 'attivo' => true]);
        $locale->assignRole('impresa');

        foreach (['ditta.vecchia', 'info@ditta.it'] as $identita) {
            $this->from('/login')->post('/login', ['username' => $identita, 'password' => 'password'])
                ->assertSessionHasErrors(['username' => trans('auth.failed')]);
            $this->assertGuest();
        }
    }

    public function test_utente_ad_entra(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_GESTORI']));

        $this->post('/login', ['username' => 'm.rossi', 'password' => 'pw'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    public function test_directory_irraggiungibile_mostra_errore_e_non_prova_locale(): void
    {
        $this->dir->nonDisponibile = true;
        User::factory()->create(['username' => 'vecchio.utente']);

        $this->from('/login')->post('/login', ['username' => 'vecchio.utente', 'password' => 'password'])
            ->assertSessionHasErrors(['username' => 'Autenticazione dei dipendenti temporaneamente non disponibile. Riprova più tardi.']);
        $this->assertGuest();
    }

    public function test_migration_disattiva_tutti_i_locali(): void
    {
        $locali = collect(['impresa', 'segnalatore', 'admin'])->map(function (string $ruolo) {
            $u = User::factory()->create(['auth_source' => 'locale', 'attivo' => true]);
            $u->assignRole($ruolo);

            return $u;
        });
        $ldap = User::factory()->create(['auth_source' => 'ldap', 'attivo' => true]);
        $spid = User::factory()->create(['auth_source' => 'spid', 'attivo' => true]);

        (require database_path('migrations/2026_10_23_000001_disattiva_account_locali.php'))->up();

        $locali->each(fn (User $u) => $this->assertFalse((bool) $u->fresh()->attivo));
        $this->assertTrue((bool) $ldap->fresh()->attivo);
        $this->assertTrue((bool) $spid->fresh()->attivo);
    }

    public function test_utenti_non_si_creano_da_admin(): void
    {
        $this->assertFalse(Route::has('admin.utenti.create'));
        $this->assertFalse(Route::has('admin.utenti.store'));
    }

    public function test_modifica_utente_non_offre_ruolo_impresa(): void
    {
        $admin = User::factory()->create(['auth_source' => 'ldap']);
        $admin->assignRole('admin');
        $utente = User::factory()->create(['auth_source' => 'ldap']);
        $utente->assignRole('gestore');

        $this->actingAs($admin)->get(route('admin.utenti.edit', $utente))->assertOk()
            ->assertDontSee('value="impresa"', false)
            ->assertDontSee('name="id_impresa"', false);
    }

    public function test_modifica_di_un_utente_ditta_non_tocca_ruolo_ne_impresa(): void
    {
        $admin = User::factory()->create(['auth_source' => 'ldap']);
        $admin->assignRole('admin');
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Test']);
        $ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa]);
        $ditta->assignRole('impresa');

        $this->actingAs($admin)->put(route('admin.utenti.update', $ditta), [
            'name' => 'Nuovo nome', 'username' => $ditta->username, 'email' => $ditta->email,
            'ruolo' => 'segnalatore', 'attivo' => '1',
        ])->assertSessionHasNoErrors();

        $ditta->refresh();
        $this->assertTrue($ditta->hasRole('impresa'));
        $this->assertSame($impresa->id_impresa, $ditta->id_impresa);
        $this->assertSame('Nuovo nome', $ditta->name);
    }
}
