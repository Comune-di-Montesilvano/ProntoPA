<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class SetupWizardTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * Deve combaciare con SETUP_TOKEN nell'.env usato da `php artisan serve`
     * durante il job Dusk in CI (processo separato da questo test: non è
     * possibile impostarlo da qui via config()).
     */
    private const TOKEN = 'dusk-e2e-setup-token';

    public function test_wizard_crea_il_primo_admin(): void
    {
        $this->assertSame(0, User::count(), 'Il wizard è testabile solo su DB senza utenti.');

        $this->browse(function (Browser $browser) {
            $browser->visit('/setup')
                ->assertSee('Setup iniziale ProntoPA')
                ->type('token', self::TOKEN)
                ->type('email', 'admin.e2e@example.test')
                ->type('password', 'Sup3rSegreta1')
                ->type('password_confirmation', 'Sup3rSegreta1')
                ->press('Invia codice di conferma')
                ->waitForLocation('/setup/verifica');

            $otp = $this->leggiOtpDalLog();

            $browser->type('otp', $otp)
                ->press('Conferma e crea account')
                ->waitForLocation('/login')
                ->assertSee('Account amministratore creato');
        });

        $this->assertSame(1, User::count());
        $this->assertTrue(User::first()->amministratore);
    }

    /**
     * Il DuskTestCase gira in un processo separato dal `php artisan serve`
     * usato in CI: niente Notification::fake() qui. Con MAIL_MAILER=log
     * (impostato nel job Dusk) l'email finisce in storage/logs/laravel.log,
     * da cui estraiamo l'OTP invece di dipendere da un server SMTP finto.
     */
    private function leggiOtpDalLog(): string
    {
        $log = storage_path('logs/laravel.log');
        $this->assertFileExists($log, 'MAIL_MAILER=log atteso per il job Dusk.');

        $contenuto = file_get_contents($log);
        preg_match_all('/\*\*(\d{6})\*\*/', $contenuto, $matches);

        $this->assertNotEmpty($matches[1], 'Nessun OTP trovato nel log email.');

        return end($matches[1]);
    }
}
