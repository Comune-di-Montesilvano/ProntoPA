<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureSetupComplete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Testa la classe middleware direttamente (non via HTTP): il middleware
 * si auto-esclude quando app()->environment('testing') per non rompere
 * il resto della suite (gli utenti sono creati via factory, non tramite
 * il wizard) — qui sovrascriviamo il binding 'env' del container per
 * simulare un ambiente "production" e verificare la logica reale.
 */
class EnsureSetupCompleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('env', 'production');
    }

    private function requestPerDashboard(): Request
    {
        $request = Request::create('/dashboard', 'GET');
        $request->setRouteResolver(fn () => tap(Route::get('/dashboard', fn () => 'ok'))->bind($request));

        return $request;
    }

    public function test_reindirizza_a_setup_se_setup_token_configurato_e_nessun_utente(): void
    {
        Config::set('app.setup_token', 'segreto');

        $response = (new EnsureSetupComplete)->handle($this->requestPerDashboard(), fn ($req) => response('passato'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/setup', $response->headers->get('Location'));
    }

    public function test_lascia_passare_se_utente_gia_esiste(): void
    {
        Config::set('app.setup_token', 'segreto');
        User::factory()->create();

        $response = (new EnsureSetupComplete)->handle($this->requestPerDashboard(), fn ($req) => response('passato'));

        $this->assertSame('passato', $response->getContent());
    }

    public function test_lascia_passare_se_setup_token_non_configurato(): void
    {
        Config::set('app.setup_token', null);

        $response = (new EnsureSetupComplete)->handle($this->requestPerDashboard(), fn ($req) => response('passato'));

        $this->assertSame('passato', $response->getContent());
    }
}
