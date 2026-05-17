<?php

namespace Tests\Feature\Auth;

use App\Models\Istituto;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_registration_screen_is_available(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
    }

    public function test_new_users_can_register_but_account_stays_pending(): void
    {
        Istituto::create([
            'descrizione' => 'Comune Test',
            'tipo' => 'Comune',
            'tipo_ente' => 'comune',
            'partita_iva' => '12345678901',
            'domini_email_istituzionali' => 'comune.test.it',
            'attivo' => true,
        ]);

        $response = $this->post('/register', [
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'partita_iva' => '12345678901',
            'email' => 'mario.rossi@comune.test.it',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'mario.rossi@comune.test.it')->first();

        $this->assertNotNull($user);
        $this->assertSame('pending', $user->approval_status);
        $this->assertFalse((bool) $user->attivo);
        $this->assertGuest();
        $response->assertRedirect('/login');
    }

    public function test_registration_rejects_shared_or_non_institutional_email(): void
    {
        Istituto::create([
            'descrizione' => 'Comune Test',
            'tipo' => 'Comune',
            'tipo_ente' => 'comune',
            'partita_iva' => '12345678901',
            'domini_email_istituzionali' => 'comune.test.it',
            'attivo' => true,
        ]);

        $response = $this->from('/register')->post('/register', [
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'partita_iva' => '12345678901',
            'email' => 'info@comune.test.it',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
    }
}
