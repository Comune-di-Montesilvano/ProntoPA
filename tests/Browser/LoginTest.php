<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginTest extends DuskTestCase
{
    use DatabaseMigrations;

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
                ->waitForLocation('/dashboard')
                ->assertPathIs('/dashboard');
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
                ->waitForText('auth.failed')
                ->assertPathIs('/login');
        });
    }
}
