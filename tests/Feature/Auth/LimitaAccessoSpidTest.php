<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LimitaAccessoSpidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function spid(bool $verificato): User
    {
        $user = User::factory()->create([
            'auth_source' => 'spid', 'codice_fiscale' => 'RSSMRA80A01G482X', 'password' => null,
            'email_verified_at' => $verificato ? now() : null,
        ]);
        $user->assignRole('segnalatore');

        return $user;
    }

    public function test_spid_non_verificato_va_alla_verifica(): void
    {
        $user = $this->spid(false);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('spid.attesa'))->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
    }

    public function test_spid_verificato_senza_deleghe_vede_solo_le_deleghe(): void
    {
        $user = $this->spid(true);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('scuola.deleghe.index'));
        $this->actingAs($user)->get(route('segnalatore.dashboard'))->assertRedirect(route('scuola.deleghe.index'));
        $this->actingAs($user)->get(route('scuola.deleghe.index'))->assertOk()->assertSee('delega');
    }

    public function test_spid_puo_sempre_modificare_profilo_e_uscire(): void
    {
        $user = $this->spid(false);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        $this->actingAs($user)->post(route('logout'))->assertRedirect();
    }

    public function test_utenti_non_spid_non_toccati(): void
    {
        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        $this->actingAs($user)->get(route('segnalatore.dashboard'))->assertOk();
    }
}
