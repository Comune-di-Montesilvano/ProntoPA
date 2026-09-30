<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stessa email su un utente AD e su una ditta locale (email non più unica):
 * conferma password e profilo devono riferirsi all'utente loggato.
 */
class EmailCondivisaTest extends TestCase
{
    use RefreshDatabase;

    private User $ditta;

    private User $ad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->ad = User::factory()->create(['email' => 'stessa@ente.it', 'auth_source' => 'ldap', 'ldap_guid' => 'g-1', 'password' => null]);
        $this->ditta = User::factory()->create(['email' => 'stessa@ente.it']);
    }

    public function test_ditta_conferma_la_propria_password(): void
    {
        $this->actingAs($this->ditta)->post('/confirm-password', ['password' => 'password'])
            ->assertSessionHasNoErrors();
    }

    public function test_utente_ad_non_conferma_con_la_password_della_ditta(): void
    {
        $this->actingAs($this->ad)->post('/confirm-password', ['password' => 'password'])
            ->assertSessionHasErrors('password');
    }

    public function test_ditta_aggiorna_il_profilo_con_email_condivisa_con_utente_ad(): void
    {
        $this->actingAs($this->ditta)->patch('/profile', ['name' => 'Ditta Nuovo Nome', 'email' => 'stessa@ente.it'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Ditta Nuovo Nome', $this->ditta->fresh()->name);
    }
}
