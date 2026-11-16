<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use \Tests\Support\DelegheFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_account_locale_vede_2fa_ma_non_cambio_password(): void
    {
        $this->actingAs(User::factory()->create())->get('/profile')
            ->assertDontSee('Update Password')
            ->assertSee('Autenticazione a due fattori');
    }

    public function test_account_spid_e_ad_non_vedono_password_e_2fa(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $spid = $this->utenteSpid();
        $this->delega($spid, $this->istitutoConPlessi(), null, \App\Models\Delega::ATTIVA);
        $ldap = User::factory()->create(['auth_source' => 'ldap']);

        foreach ([$spid, $ldap] as $user) {
            $this->actingAs($user)->get('/profile')
                ->assertOk()
                ->assertDontSee('Update Password')
                ->assertDontSee('Autenticazione a due fattori');
        }
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_profile_delete_route_is_not_available(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response->assertMethodNotAllowed();
        $this->assertNotNull($user->fresh());
    }

    public function test_profile_delete_route_rejects_requests_even_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response->assertMethodNotAllowed();

        $this->assertNotNull($user->fresh());
    }

    public function test_nome_di_spid_e_dominio_non_modificabile_dal_profilo(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $spid = $this->utenteSpid();
        $this->delega($spid, $this->istitutoConPlessi(), null, \App\Models\Delega::ATTIVA);
        $spid->forceFill(['name' => 'Mario Rossi'])->save();
        $ldap = User::factory()->create(['auth_source' => 'ldap', 'name' => 'Anna Bianchi']);

        foreach ([[$spid, 'Mario Rossi'], [$ldap, 'Anna Bianchi']] as [$user, $nome]) {
            $this->actingAs($user)->get('/profile')->assertOk()
                ->assertDontSee('name="name"', false)
                ->assertSee($nome);
            $this->actingAs($user)
                ->patch('/profile', ['name' => 'ISTITUTO COMPRENSIVO', 'email' => $user->email, 'telefono' => '085 123'])
                ->assertSessionHasNoErrors();
            $this->assertSame($nome, $user->fresh()->name);
            $this->assertSame('085 123', $user->fresh()->telefono);
        }
    }

    public function test_account_locale_cambia_ancora_il_nome(): void
    {
        $user = User::factory()->create(['name' => 'Vecchio']);

        $this->actingAs($user)->patch('/profile', ['name' => 'Nuovo', 'email' => $user->email])->assertSessionHasNoErrors();

        $this->assertSame('Nuovo', $user->fresh()->name);
    }
}
