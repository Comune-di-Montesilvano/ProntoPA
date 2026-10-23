<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** v0.8.19: registrazione, reset password, approvazione e verifica annuale non esistono più. */
class RotteRimosseTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotte_degli_account_locali_non_esistono(): void
    {
        foreach ([
            'register', 'password.request', 'password.email', 'password.reset', 'password.store',
            'password.update', 'account.verification.annual', 'admin.utenti.approve', 'admin.utenti.reject',
        ] as $nome) {
            $this->assertFalse(Route::has($nome), "La rotta {$nome} esiste ancora");
        }
    }

    public function test_register_risponde_404(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
    }

    public function test_comando_verifica_annuale_rimosso(): void
    {
        $this->assertArrayNotHasKey('accounts:annual-check', Artisan::all());
    }

    public function test_login_senza_link_registrazione_e_password_dimenticata(): void
    {
        $this->get('/login')->assertOk()
            ->assertDontSee('/register', false)
            ->assertDontSee('forgot-password', false);
    }
}
