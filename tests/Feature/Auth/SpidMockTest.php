<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpidMockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_mock_disattivo_404(): void
    {
        config(['oidc.mock' => false]);

        $this->get('/auth/spid/mock')->assertNotFound();
        $this->post('/auth/spid/mock', ['codice_fiscale' => 'RSSMRA80A01G482X'])->assertNotFound();
    }

    public function test_start_con_mock_va_al_simulatore(): void
    {
        config(['oidc.mock' => true]);

        $this->get('/auth/spid')->assertRedirect(route('spid.mock'));
        $this->get('/auth/spid/mock')->assertOk()->assertSee('Simulatore SPID');
    }

    public function test_mock_nuovo_utente_porta_a_completa_profilo(): void
    {
        config(['oidc.mock' => true]);

        $this->post('/auth/spid/mock', ['codice_fiscale' => 'tinit-rssmra80a01g482x', 'nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario@example.it'])
            ->assertRedirect(route('spid.completa-profilo'));

        $this->assertSame('RSSMRA80A01G482X', session('spid.identita')['codice_fiscale']);
    }

    public function test_mock_utente_esistente_entra(): void
    {
        config(['oidc.mock' => true]);
        $user = User::factory()->create(['auth_source' => 'spid', 'codice_fiscale' => 'RSSMRA80A01G482X', 'password' => null]);

        $this->post('/auth/spid/mock', ['codice_fiscale' => 'RSSMRA80A01G482X', 'nome' => 'Mario', 'cognome' => 'Rossi']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_mock_codice_fiscale_non_valido(): void
    {
        config(['oidc.mock' => true]);

        $this->post('/auth/spid/mock', ['codice_fiscale' => '123', 'nome' => 'Mario', 'cognome' => 'Rossi'])
            ->assertSessionHasErrors('codice_fiscale');
    }
}
