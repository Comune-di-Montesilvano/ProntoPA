<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Directory\Directory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

/**
 * Il limite dei tentativi deve valere per l'identità reale, non per la
 * stringa digitata: "a\m.rossi", "b\m.rossi", "m.rossi@ente.local" sono la
 * stessa persona per AD.
 */
class LoginRateLimitTest extends TestCase
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

    public function test_varianti_dominio_e_upn_condividono_il_limite_ad(): void
    {
        $this->dir->aggiungi('giusta', FakeDirectory::identita('m.rossi', ['PRONTOPA_GESTORI']));

        foreach (['a\\m.rossi', 'b\\m.rossi', 'M.ROSSI', 'm.rossi@ente.local', 'c\\m.rossi'] as $variante) {
            $this->post('/login', ['username' => $variante, 'password' => 'sbagliata']);
        }

        $this->post('/login', ['username' => 'z\\m.rossi', 'password' => 'giusta']);

        $this->assertGuest();
    }

    public function test_varianti_maiuscole_email_condividono_il_limite(): void
    {
        User::factory()->create(['email' => 'mario@ditta.it']);

        foreach (['mario@ditta.it', 'MARIO@ditta.it', ' Mario@Ditta.it', 'mario@DITTA.IT', 'mArio@ditta.it'] as $variante) {
            $this->post('/login', ['username' => $variante, 'password' => 'sbagliata']);
        }

        $this->post('/login', ['username' => 'Mario@ditta.IT', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_ad_non_disponibile_conta_come_tentativo_fallito(): void
    {
        $this->dir->nonDisponibile = true;
        User::factory()->create(['username' => 'vecchio.utente']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'vecchio.utente', 'password' => 'sbagliata']);
        }

        $this->post('/login', ['username' => 'vecchio.utente', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_limite_per_ip_su_username_diversi(): void
    {
        $this->dir->aggiungi('giusta', FakeDirectory::identita('ultimo', ['PRONTOPA_GESTORI']));

        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', ['username' => "utente{$i}", 'password' => 'sbagliata']);
        }

        $this->post('/login', ['username' => 'ultimo', 'password' => 'giusta']);

        $this->assertGuest();
    }
}
