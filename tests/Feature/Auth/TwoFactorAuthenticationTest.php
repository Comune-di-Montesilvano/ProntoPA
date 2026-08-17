<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    private function confirmPasswordInSession(User $user): void
    {
        $this->actingAs($user);
        session(['auth.password_confirmed_at' => time()]);
    }

    public function test_login_normale_senza_2fa_non_richiede_challenge(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_login_con_2fa_attivo_richiede_challenge(): void
    {
        $user = User::factory()->create();
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('two-factor.login'));
        $this->assertEquals($user->id, session('login.id'));
    }

    public function test_challenge_con_codice_totp_valido_completa_login(): void
    {
        $user = User::factory()->create();
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['username' => $user->username, 'password' => 'password']);

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = (new Google2FA())->getCurrentOtp($secret);

        $response = $this->post('/two-factor-challenge', ['code' => $code]);

        $this->assertAuthenticatedAs($user->fresh());
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_challenge_con_codice_errato_fallisce(): void
    {
        $user = User::factory()->create();
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['username' => $user->username, 'password' => 'password']);

        $response = $this->post('/two-factor-challenge', ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_challenge_con_recovery_code_valido_completa_login_e_lo_consuma(): void
    {
        $user = User::factory()->create();
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $user = $user->fresh();
        $recoveryCode = $user->recoveryCodes()[0];

        $this->post('/login', ['username' => $user->username, 'password' => 'password']);

        $response = $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCode]);

        $this->assertAuthenticatedAs($user->fresh());
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertNotContains($recoveryCode, $user->fresh()->recoveryCodes());
    }

    public function test_challenge_senza_sessione_login_redirige_al_login(): void
    {
        $response = $this->get('/two-factor-challenge');

        $response->assertRedirect(route('login'));
    }

    public function test_utente_puo_abilitare_2fa_da_profilo(): void
    {
        $user = User::factory()->create();
        $this->confirmPasswordInSession($user);

        $response = $this->post(route('two-factor.enable'));

        $response->assertRedirect();
        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_utente_conferma_2fa_con_codice_valido(): void
    {
        $user = User::factory()->create();
        $this->confirmPasswordInSession($user);
        app(EnableTwoFactorAuthentication::class)($user);

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = (new Google2FA())->getCurrentOtp($secret);

        $response = $this->post(route('two-factor.confirm'), ['code' => $code]);

        $response->assertRedirect();
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_utente_puo_disattivare_2fa(): void
    {
        $user = User::factory()->create();
        $this->confirmPasswordInSession($user);
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $response = $this->delete(route('two-factor.disable'));

        $response->assertRedirect();
        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_gestione_2fa_richiede_conferma_password_recente(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user); // niente password_confirmed_at in sessione

        $response = $this->post(route('two-factor.enable'));

        $response->assertRedirect(route('password.confirm'));
    }
}
