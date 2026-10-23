<?php

namespace Tests\Browser;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginTest extends DuskTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_utente_puo_accedere_e_vede_la_dashboard(): void
    {
        // Solo AD (v0.8.19): utente del MockDirectory (LDAP_HOST=mock in CI).
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->assertSee('Accesso personale del comune')
                ->type('username', 'mock.segnalatore')
                ->type('password', 'mock.segnalatore')
                ->press('Accedi')
                // /dashboard è solo un dispatcher: RoleDashboardController
                // reindirizza subito alla dashboard del ruolo (qui
                // segnalatore.dashboard) — non resta mai su /dashboard.
                ->waitUntilMissing('#password')
                ->assertPathIsNot('/login')
                ->assertSee('Le mie segnalazioni');
        });
    }

    public function test_credenziali_errate_restano_sulla_pagina_di_login(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('username', 'mock.segnalatore')
                ->type('password', 'password-sbagliata')
                ->press('Accedi')
                ->waitForText('Credenziali non valide.')
                ->assertPathIs('/login');
        });
    }
}
