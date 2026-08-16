<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SetupOtpNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
        $this->seed(RolesAndPermissionsSeeder::class);
        Config::set('app.setup_token', 'il-token-segreto');
    }

    public function test_mostra_form_se_nessun_utente_esiste(): void
    {
        $this->get(route('setup.show'))->assertOk();
    }

    public function test_reindirizza_al_login_se_un_admin_esiste_gia(): void
    {
        User::factory()->create();

        $this->get(route('setup.show'))->assertRedirect(route('login'));
    }

    public function test_token_errato_viene_rifiutato(): void
    {
        Notification::fake();

        $response = $this->post(route('setup.richiedi-otp'), [
            'token'                 => 'token-sbagliato',
            'email'                 => 'admin@test.it',
            'password'              => 'Sup3rSegreta1',
            'password_confirmation' => 'Sup3rSegreta1',
        ]);

        $response->assertSessionHasErrors('token');
        $this->assertDatabaseCount('users', 0);
        Notification::assertNothingSent();
    }

    public function test_token_corretto_invia_otp_e_non_crea_ancora_utente(): void
    {
        Notification::fake();

        $response = $this->post(route('setup.richiedi-otp'), [
            'token'                 => 'il-token-segreto',
            'email'                 => 'admin@test.it',
            'password'              => 'Sup3rSegreta1',
            'password_confirmation' => 'Sup3rSegreta1',
        ]);

        $response->assertRedirect(route('setup.verify'));
        $this->assertDatabaseCount('users', 0);

        Notification::assertSentOnDemand(
            SetupOtpNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'admin@test.it'
        );
    }

    public function test_otp_corretto_crea_admin(): void
    {
        $this->withSession(['setup_otp' => [
            'otp'           => '123456',
            'email'         => 'admin@test.it',
            'password_hash' => bcrypt('Sup3rSegreta1'),
            'scade_alle'    => now()->addMinutes(10)->timestamp,
        ]])->post(route('setup.conferma'), ['otp' => '123456'])
            ->assertRedirect(route('login'));

        $user = User::where('email', 'admin@test.it')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->attivo);
    }

    public function test_otp_errato_non_crea_utente(): void
    {
        $this->withSession(['setup_otp' => [
            'otp'           => '123456',
            'email'         => 'admin@test.it',
            'password_hash' => bcrypt('Sup3rSegreta1'),
            'scade_alle'    => now()->addMinutes(10)->timestamp,
        ]])->post(route('setup.conferma'), ['otp' => '000000'])
            ->assertSessionHasErrors('otp');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_otp_scaduto_viene_rifiutato(): void
    {
        $this->withSession(['setup_otp' => [
            'otp'           => '123456',
            'email'         => 'admin@test.it',
            'password_hash' => bcrypt('Sup3rSegreta1'),
            'scade_alle'    => now()->subMinute()->timestamp,
        ]])->post(route('setup.conferma'), ['otp' => '123456'])
            ->assertRedirect(route('setup.show'));

        $this->assertDatabaseCount('users', 0);
    }

    public function test_verifica_senza_richiesta_precedente_reindirizza_a_setup(): void
    {
        $this->get(route('setup.verify'))->assertRedirect(route('setup.show'));
    }

    public function test_otp_ha_rate_limit(): void
    {
        // Ripristina il throttle per questo test (escluso in setUp per gli altri).
        $this->withMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->withSession(['setup_otp' => [
            'otp'           => '123456',
            'email'         => 'admin@test.it',
            'password_hash' => bcrypt('Sup3rSegreta1'),
            'scade_alle'    => now()->addMinutes(10)->timestamp,
        ]]);

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('setup.conferma'), ['otp' => '000000']);
        }

        $this->post(route('setup.conferma'), ['otp' => '000000'])
            ->assertStatus(429);
    }
}
