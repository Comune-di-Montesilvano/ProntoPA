<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UtentiEmailUnicaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['amministratore' => true]);
        $this->admin->assignRole('admin');
    }

    private function dati(string $email, string $username): array
    {
        return ['name' => 'Ditta Test', 'username' => $username, 'email' => $email,
            'password' => 'Password123', 'ruolo' => 'impresa', 'attivo' => true];
    }

    public function test_ditta_puo_usare_email_gia_usata_da_utente_ad(): void
    {
        User::factory()->create(['email' => 'stessa@ente.it', 'auth_source' => 'ldap', 'ldap_guid' => 'g-1']);

        $this->actingAs($this->admin)->post(route('admin.utenti.store'), $this->dati('stessa@ente.it', 'ditta1'))
            ->assertSessionHasNoErrors();
    }

    public function test_username_della_ditta_e_la_sua_email(): void
    {
        $this->actingAs($this->admin)->post(route('admin.utenti.store'), $this->dati('Info@Ditta.it', 'scelto-a-mano'))
            ->assertSessionHasNoErrors();

        $ditta = User::where('email', 'info@ditta.it')->firstOrFail();
        $this->assertSame('info@ditta.it', $ditta->username);

        $dati = $this->dati('nuova@ditta.it', 'altro') + ['_method' => 'PUT'];
        $this->actingAs($this->admin)->put(route('admin.utenti.update', $ditta), $dati)->assertSessionHasNoErrors();
        $this->assertSame('nuova@ditta.it', $ditta->fresh()->username);
    }

    public function test_ditta_non_puo_usare_email_di_altro_account_locale_attivo(): void
    {
        User::factory()->create(['email' => 'presa@ditta.it']);

        $this->actingAs($this->admin)->post(route('admin.utenti.store'), $this->dati('presa@ditta.it', 'ditta2'))
            ->assertSessionHasErrors('email');
    }
}
