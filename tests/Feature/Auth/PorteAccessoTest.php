<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** v0.8.19: /login solo personale AD, /accesso solo SPID/CIE per scuole e ditte. */
class PorteAccessoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ImpostazioniSeeder::class);
        config(['oidc.mock' => true]);
    }

    public function test_accesso_ha_solo_spid_e_rimanda_al_login_operatori(): void
    {
        $this->get('/accesso')->assertOk()
            ->assertSee('Accesso scuole e ditte')
            ->assertSee(route('spid.start'), false)
            ->assertSee(route('login'), false)
            ->assertDontSee('name="password"', false);
    }

    public function test_login_ha_solo_credenziali_e_rimanda_ad_accesso(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Accesso personale del comune')
            ->assertSee('name="password"', false)
            ->assertSee(route('accesso'), false)
            ->assertDontSee(route('spid.start'), false)
            ->assertDontSee('email (ditte)');
    }

    public function test_home_mostra_entrambi_spid_prima(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $spid = strpos($html, 'href="'.route('accesso').'"');
        $operatori = strpos($html, 'href="'.route('login').'"');
        $this->assertNotFalse($spid);
        $this->assertNotFalse($operatori);
        $this->assertStringContainsString('Personale del comune', $html);
        $this->assertStringContainsString('Scuole e ditte', $html);
    }

    public function test_utente_autenticato_su_accesso_va_alla_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['auth_source' => 'ldap']))
            ->get('/accesso')
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_errore_spid_torna_ad_accesso(): void
    {
        config(['oidc.mock' => false]);

        $this->get('/auth/spid/callback?state=sbagliato&code=x')
            ->assertRedirect(route('accesso'));
    }
}
