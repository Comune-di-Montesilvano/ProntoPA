<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\CodiceAccessoNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CodiceAccessoEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $ditta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();

        $this->ditta = User::factory()->create(['email' => 'mario@ditta.it']);
        $this->ditta->assignRole('impresa');
    }

    private function loginEPrendiCodice(): string
    {
        $this->post('/login', ['username' => 'mario@ditta.it', 'password' => 'password']);

        $codice = null;
        Notification::assertSentTo($this->ditta, CodiceAccessoNotification::class, function ($n) use (&$codice) {
            $codice = $n->codice;

            return true;
        });

        return $codice;
    }

    public function test_login_ditta_invia_codice_e_mostra_challenge_email(): void
    {
        $codice = $this->loginEPrendiCodice();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $codice);
        $this->assertGuest();
        $this->get('/two-factor-challenge')->assertOk()->assertSee('Abbiamo inviato un codice');
    }

    public function test_codice_corretto_completa_il_login_e_pulisce_la_sessione(): void
    {
        $codice = $this->loginEPrendiCodice();

        $response = $this->post('/two-factor-challenge', ['code' => $codice]);

        $this->assertAuthenticatedAs($this->ditta);
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertNull(session('login.id'));
        $this->assertNull(session('login.metodo'));
    }

    public function test_codice_errato_non_autentica(): void
    {
        $this->loginEPrendiCodice();

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_dopo_cinque_errori_anche_il_codice_giusto_non_vale(): void
    {
        $codice = $this->loginEPrendiCodice();
        $sbagliato = $codice === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 5; $i++) {
            $this->post('/two-factor-challenge', ['code' => $sbagliato]);
        }
        $this->post('/two-factor-challenge', ['code' => $codice]);

        $this->assertGuest();
    }

    public function test_servizio_invalida_il_codice_al_quinto_errore_senza_throttle(): void
    {
        $servizio = app(\App\Services\Auth\CodiceAccessoEmail::class);
        $servizio->invia($this->ditta);

        $codice = null;
        Notification::assertSentTo($this->ditta, CodiceAccessoNotification::class, function ($n) use (&$codice) {
            $codice = $n->codice;

            return true;
        });
        $sbagliato = $codice === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 4; $i++) {
            $this->assertFalse($servizio->verifica($this->ditta, $sbagliato));
        }
        $this->assertFalse($servizio->verifica($this->ditta, $sbagliato));
        $this->assertFalse($servizio->verifica($this->ditta, $codice));
    }

    public function test_servizio_accetta_il_codice_dopo_quattro_errori(): void
    {
        $servizio = app(\App\Services\Auth\CodiceAccessoEmail::class);
        $servizio->invia($this->ditta);

        $codice = null;
        Notification::assertSentTo($this->ditta, CodiceAccessoNotification::class, function ($n) use (&$codice) {
            $codice = $n->codice;

            return true;
        });
        $sbagliato = $codice === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 4; $i++) {
            $servizio->verifica($this->ditta, $sbagliato);
        }

        $this->assertTrue($servizio->verifica($this->ditta, $codice));
        $this->assertFalse($servizio->verifica($this->ditta, $codice), 'codice consumato al primo uso');
    }

    public function test_codice_scaduto_dopo_dieci_minuti(): void
    {
        $codice = $this->loginEPrendiCodice();

        $this->travel(11)->minutes();
        $this->post('/two-factor-challenge', ['code' => $codice]);

        $this->assertGuest();
    }

    public function test_reinvio_genera_nuovo_codice_e_invalida_il_vecchio(): void
    {
        $vecchio = $this->loginEPrendiCodice();
        Notification::fake();

        $this->post('/two-factor-challenge/reinvia')->assertRedirect(route('two-factor.login'));

        $nuovo = null;
        Notification::assertSentTo($this->ditta, CodiceAccessoNotification::class, function ($n) use (&$nuovo) {
            $nuovo = $n->codice;

            return true;
        });

        if ($nuovo !== $vecchio) {
            $this->post('/two-factor-challenge', ['code' => $vecchio]);
            $this->assertGuest();
        }

        $this->post('/two-factor-challenge', ['code' => $nuovo]);
        $this->assertAuthenticatedAs($this->ditta);
    }

    public function test_reinvio_senza_login_in_corso_torna_al_login(): void
    {
        $this->post('/two-factor-challenge/reinvia')->assertRedirect(route('login'));

        Notification::assertNothingSent();
    }
}
