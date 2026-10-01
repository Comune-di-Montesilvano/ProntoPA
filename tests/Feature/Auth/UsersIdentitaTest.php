<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\TestCase;

class UsersIdentitaTest extends TestCase
{
    use RefreshDatabase;

    public function test_nuovo_utente_ha_auth_source_locale_di_default(): void
    {
        $user = User::factory()->create();

        $this->assertSame('locale', $user->fresh()->auth_source);
        $this->assertTrue($user->fresh()->isLocale());
    }

    public function test_due_utenti_possono_avere_la_stessa_email(): void
    {
        User::factory()->create(['email' => 'stessa@ente.it']);
        User::factory()->create(['email' => 'stessa@ente.it']);

        $this->assertSame(2, User::where('email', 'stessa@ente.it')->count());
    }

    public function test_utente_ldap_senza_password_si_salva(): void
    {
        $user = User::factory()->create(['auth_source' => 'ldap', 'password' => null, 'ldap_guid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        $this->assertNull($user->fresh()->password);
    }

    public function test_ldap_guid_e_unico(): void
    {
        User::factory()->create(['auth_source' => 'ldap', 'ldap_guid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        $this->expectException(QueryException::class);
        User::factory()->create(['auth_source' => 'ldap', 'ldap_guid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);
    }

    public function test_metodo_secondo_fattore(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $locale = User::factory()->create();
        $this->assertNull($locale->metodoSecondoFattore());

        $ditta = User::factory()->create();
        $ditta->assignRole('impresa');
        $this->assertSame('email', $ditta->metodoSecondoFattore());

        $optIn = User::factory()->create(['two_factor_metodo' => 'email']);
        $this->assertSame('email', $optIn->metodoSecondoFattore());

        $totp = User::factory()->create();
        $totp->assignRole('impresa');
        app(EnableTwoFactorAuthentication::class)($totp);
        $totp->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->assertSame('totp', $totp->fresh()->metodoSecondoFattore());

        $ldap = User::factory()->create(['auth_source' => 'ldap', 'two_factor_metodo' => 'email']);
        $ldap->assignRole('impresa');
        $this->assertNull($ldap->metodoSecondoFattore());
    }
}
