<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
    }

    public function test_reset_va_solo_alla_ditta_se_email_condivisa_con_utente_ad(): void
    {
        $ad = User::factory()->create(['email' => 'stessa@ente.it', 'auth_source' => 'ldap', 'ldap_guid' => 'g-1', 'password' => null]);
        $ditta = User::factory()->create(['email' => 'stessa@ente.it']);

        $this->post('/forgot-password', ['email' => 'stessa@ente.it']);

        Notification::assertSentTo($ditta, ResetPassword::class);
        Notification::assertNotSentTo($ad, ResetPassword::class);
    }

    public function test_nessun_reset_per_utente_solo_ad(): void
    {
        $ad = User::factory()->create(['email' => 'solo@ente.it', 'auth_source' => 'ldap', 'ldap_guid' => 'g-2', 'password' => null]);

        $this->post('/forgot-password', ['email' => 'solo@ente.it']);

        Notification::assertNotSentTo($ad, ResetPassword::class);
    }

    public function test_nessun_reset_per_account_locale_disattivato(): void
    {
        $vecchio = User::factory()->create(['email' => 'vecchio@ente.it', 'attivo' => false]);

        $this->post('/forgot-password', ['email' => 'vecchio@ente.it']);

        Notification::assertNotSentTo($vecchio, ResetPassword::class);
    }
}
