<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\AccessoLdapNegato;
use App\Services\Auth\LdapLoginService;
use App\Services\Directory\Directory;
use App\Services\Directory\DirectoryNonDisponibile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

class LdapLoginServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeDirectory $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->dir = new FakeDirectory();
        $this->app->instance(Directory::class, $this->dir);
    }

    private function service(): LdapLoginService
    {
        return app(LdapLoginService::class);
    }

    public function test_primo_login_crea_utente_ldap_con_ruolo(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_GESTORI'], 'M.Rossi@Ente.local'));

        $user = $this->service()->login('m.rossi', 'pw');

        $this->assertSame('ldap', $user->auth_source);
        $this->assertSame('m.rossi', $user->username);
        $this->assertSame('m.rossi@ente.local', $user->email);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('gestore'));
        $this->assertTrue($user->gestore_segnalazioni);
        $this->assertFalse($user->supervisore_segnalazioni);
        $this->assertSame(1, $user->id_provenienza);
        $this->assertNotNull($user->last_login);
    }

    public function test_login_successivo_ritrova_per_guid_e_ricalcola_ruolo(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_GESTORI'], guid: 'guid-1'));
        $primo = $this->service()->login('m.rossi', 'pw');

        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_OPERAI'], guid: 'guid-1'));
        $secondo = $this->service()->login('m.rossi', 'pw');

        $this->assertSame($primo->id, $secondo->id);
        $this->assertTrue($secondo->hasRole('operaio'));
        $this->assertFalse($secondo->hasRole('gestore'));
        $this->assertFalse($secondo->gestore_segnalazioni);
    }

    public function test_urp_riceve_permesso_per_conto_e_lo_perde_se_cambia_gruppo(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('urp1', ['PRONTOPA_URP'], guid: 'g-urp'));
        $user = $this->service()->login('urp1', 'pw');

        $this->assertTrue($user->can('segnalazioni.per-conto'));
        $this->assertSame(3, $user->id_provenienza);

        $this->dir->aggiungi('pw', FakeDirectory::identita('urp1', ['PRONTOPA_SEGNALATORI'], guid: 'g-urp'));
        $user = $this->service()->login('urp1', 'pw');

        $this->assertFalse($user->fresh()->can('segnalazioni.per-conto'));
    }

    public function test_credenziali_errate_o_password_vuota_restituiscono_null(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_GESTORI']));

        $this->assertNull($this->service()->login('m.rossi', 'sbagliata'));
        $this->assertNull($this->service()->login('m.rossi', ''));
        $this->assertSame(0, User::count());
    }

    public function test_nessun_gruppo_prontopa_nega_accesso(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('esterno', ['Domain Users']));

        $this->expectException(AccessoLdapNegato::class);
        $this->service()->login('esterno', 'pw');
    }

    public function test_account_ad_senza_email_nega_accesso(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('noemail', ['PRONTOPA_OPERAI'], null));

        $this->expectException(AccessoLdapNegato::class);
        $this->service()->login('noemail', 'pw');
    }

    public function test_aggancia_account_legacy_non_ditta_con_stessa_email(): void
    {
        $legacy = User::factory()->create(['username' => 'vecchio.rossi', 'email' => 'm.rossi@ente.local', 'attivo' => false]);
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_OPERAI'], 'M.ROSSI@ente.local'));

        $user = $this->service()->login('m.rossi', 'pw');

        $this->assertSame($legacy->id, $user->id);
        $this->assertSame('ldap', $user->auth_source);
        $this->assertSame('m.rossi', $user->username);
        $this->assertNull($user->password);
        $this->assertTrue($user->attivo);
    }

    public function test_non_aggancia_ditte_ne_email_ambigue(): void
    {
        $ditta = User::factory()->create(['email' => 'info@ditta.it', 'id_impresa' => null]);
        $ditta->assignRole('impresa');
        User::factory()->create(['email' => 'doppia@ente.local']);
        User::factory()->create(['email' => 'doppia@ente.local']);

        $this->dir->aggiungi('pw', FakeDirectory::identita('a.ditta', ['PRONTOPA_OPERAI'], 'info@ditta.it', 'g-a'));
        $this->dir->aggiungi('pw', FakeDirectory::identita('a.doppia', ['PRONTOPA_OPERAI'], 'doppia@ente.local', 'g-b'));

        $this->assertNotSame($ditta->id, $this->service()->login('a.ditta', 'pw')->id);
        $this->assertSame('locale', $ditta->fresh()->auth_source);

        $this->service()->login('a.doppia', 'pw');
        $this->assertSame(2, User::where('email', 'doppia@ente.local')->where('auth_source', 'locale')->count());
        $this->assertSame(1, User::where('email', 'doppia@ente.local')->where('auth_source', 'ldap')->count());
    }

    public function test_username_occupato_da_altro_account_nega_accesso_senza_errore_db(): void
    {
        User::factory()->create(['username' => 'gestore', 'email' => 'gestore@demo.local']);
        $this->dir->aggiungi('pw', FakeDirectory::identita('gestore', ['PRONTOPA_GESTORI'], 'gestore@ente.local'));

        $this->expectException(AccessoLdapNegato::class);
        $this->service()->login('gestore', 'pw');
    }

    public function test_directory_non_disponibile_propaga_eccezione(): void
    {
        $this->dir->nonDisponibile = true;

        $this->expectException(DirectoryNonDisponibile::class);
        $this->service()->login('m.rossi', 'pw');
    }
}
