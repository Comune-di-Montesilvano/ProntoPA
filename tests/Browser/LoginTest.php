<?php

namespace Tests\Browser;

use App\Models\User;
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
        $user = User::factory()->create(['username' => 'e2e.login']);
        $user->assignRole('segnalatore');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->assertSee('Accedi')
                ->type('username', $user->username)
                ->type('password', 'password')
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
        $user = User::factory()->create(['username' => 'e2e.wrongpass']);
        $user->assignRole('segnalatore');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('username', $user->username)
                ->type('password', 'password-sbagliata')
                ->press('Accedi')
                ->waitForText('These credentials do not match our records.')
                ->assertPathIs('/login');
        });
    }
}
