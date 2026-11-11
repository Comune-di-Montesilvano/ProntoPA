<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\SyncAdFallitaNotification;
use App\Services\Auth\EsitoSyncAd;
use App\Services\Auth\SincronizzaUtentiAd;
use App\Services\Directory\Directory;
use App\Services\Segnalazioni\CoperturaTipologie;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

/** AD comanda: la sync crea, aggiorna, riattiva e disattiva gli utenti di dominio. */
class SincronizzaUtentiAdTest extends TestCase
{
    use RefreshDatabase;

    private FakeDirectory $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->dir = new FakeDirectory();
        $this->app->instance(Directory::class, $this->dir);
        Notification::fake();
    }

    /** @param list<string> $gruppi */
    private function membro(string $username, array $gruppi = ['PRONTOPA_GESTORI'], bool $abilitato = true, string|null|false $email = false): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita($username, $gruppi, $email, guid: 'guid-'.$username, abilitato: $abilitato));
    }

    /** @param array<string, mixed> $attributi */
    private function utenteLdap(string $username, array $attributi = [], string $ruolo = 'gestore'): User
    {
        $u = User::factory()->create(array_merge([
            'username' => $username, 'auth_source' => 'ldap', 'ldap_guid' => 'guid-'.$username,
            'attivo' => true, 'email' => $username.'@ente.local',
        ], $attributi));
        $u->assignRole($ruolo);

        return $u;
    }

    private function sync(bool $forza = false, bool $prova = false): EsitoSyncAd
    {
        return app(SincronizzaUtentiAd::class)->esegui($forza, $prova);
    }

    public function test_crea_gestore_da_membro(): void
    {
        $this->membro('m.rossi');

        $esito = $this->sync();

        $u = User::where('username', 'm.rossi')->firstOrFail();
        $this->assertSame('ok', $esito->stato);
        $this->assertSame(1, $esito->creati);
        $this->assertTrue($u->hasRole('gestore'));
        $this->assertTrue((bool) $u->attivo);
        $this->assertNull($u->last_login);
    }

    public function test_aggiorna_email_e_ruolo(): void
    {
        $this->utenteLdap('m.rossi', ['email' => 'vecchia@ente.local'], 'operaio');
        $this->membro('m.rossi', ['PRONTOPA_GESTORI'], email: 'nuova@ente.local');

        $esito = $this->sync();

        $u = User::where('username', 'm.rossi')->firstOrFail();
        $this->assertSame('nuova@ente.local', $u->email);
        $this->assertTrue($u->hasRole('gestore'));
        $this->assertSame(1, $esito->aggiornati);
    }

    public function test_riattiva_chi_rientra(): void
    {
        $this->utenteLdap('m.rossi', ['attivo' => false]);
        $this->membro('m.rossi');

        $esito = $this->sync();

        $this->assertTrue((bool) User::where('username', 'm.rossi')->value('attivo'));
        $this->assertSame(1, $esito->riattivati);
    }

    public function test_disattiva_chi_esce_dai_gruppi(): void
    {
        $uscito = $this->utenteLdap('ex.gestore');
        $this->membro('m.rossi');

        $esito = $this->sync();

        $this->assertFalse((bool) $uscito->fresh()->attivo);
        $this->assertTrue($uscito->fresh()->hasRole('gestore'), 'ruolo non toccato');
        $this->assertSame(1, $esito->disattivati);
    }

    public function test_disattiva_account_ad_disabilitato(): void
    {
        $u = $this->utenteLdap('m.rossi');
        $this->membro('m.rossi', abilitato: false);
        $this->membro('altro');

        $this->sync();

        $this->assertFalse((bool) $u->fresh()->attivo);
    }

    public function test_non_tocca_spid_e_locale(): void
    {
        $spid = User::factory()->create(['auth_source' => 'spid', 'attivo' => true]);
        $locale = User::factory()->create(['auth_source' => 'locale', 'attivo' => true]);
        $this->membro('m.rossi');

        $this->sync();

        $this->assertTrue((bool) $spid->fresh()->attivo);
        $this->assertTrue((bool) $locale->fresh()->attivo);
    }

    public function test_salta_senza_email(): void
    {
        $this->membro('m.rossi');
        $this->membro('senza.mail', email: null);

        $esito = $this->sync();

        $this->assertFalse(User::where('username', 'senza.mail')->exists());
        $this->assertSame(1, $esito->senzaEmail);
        $this->assertSame('ok', $esito->stato);
    }

    public function test_salta_username_occupato_e_continua(): void
    {
        User::factory()->create(['username' => 'occupato', 'auth_source' => 'spid']);
        $this->membro('occupato');
        $this->membro('m.rossi');

        $esito = $this->sync();

        $this->assertSame(1, $esito->usernameOccupati);
        $this->assertTrue(User::where('username', 'm.rossi')->exists());
    }

    public function test_directory_non_disponibile_nessuna_scrittura(): void
    {
        $u = $this->utenteLdap('m.rossi');
        $this->dir->nonDisponibile = true;

        $esito = $this->sync();

        $this->assertSame('fallita', $esito->stato);
        $this->assertNotEmpty($esito->motivo);
        $this->assertTrue((bool) $u->fresh()->attivo);
    }

    public function test_zero_membri_nessuna_disattivazione(): void
    {
        $u = $this->utenteLdap('m.rossi');

        $esito = $this->sync();

        $this->assertSame('fallita', $esito->stato);
        $this->assertStringContainsString('0 membri', (string) $esito->motivo);
        $this->assertTrue((bool) $u->fresh()->attivo);
    }

    public function test_oltre_meta_da_disattivare_si_blocca(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $n) {
            $this->utenteLdap("u.$n");
        }
        $this->membro('u.a');
        $this->membro('u.b');

        $esito = $this->sync();

        $this->assertSame('bloccata', $esito->stato);
        $this->assertSame(4, $esito->daDisattivare);
        $this->assertSame(0, $esito->disattivati);
        $this->assertSame(6, User::where('attivo', true)->count());
    }

    public function test_forza_supera_il_blocco(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $n) {
            $this->utenteLdap("u.$n");
        }
        $this->membro('u.a');
        $this->membro('u.b');

        $esito = $this->sync(forza: true);

        $this->assertSame('ok', $esito->stato);
        $this->assertSame(4, $esito->disattivati);
    }

    public function test_pochi_utenti_nessun_blocco(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $n) {
            $this->utenteLdap("u.$n");
        }
        $this->membro('u.a');

        $esito = $this->sync();

        $this->assertSame('ok', $esito->stato);
        $this->assertSame(3, $esito->disattivati);
    }

    public function test_prova_non_scrive(): void
    {
        $u = $this->utenteLdap('ex');
        $this->membro('m.rossi');

        $esito = $this->sync(prova: true);

        $this->assertSame(1, $esito->creati);
        $this->assertSame(1, $esito->disattivati);
        $this->assertFalse(User::where('username', 'm.rossi')->exists());
        $this->assertTrue((bool) $u->fresh()->attivo);
        $this->assertNull(app(SincronizzaUtentiAd::class)->ultimoEsito());
    }

    public function test_esito_salvato_e_riletto(): void
    {
        $this->membro('m.rossi');

        $this->sync();

        $esito = app(SincronizzaUtentiAd::class)->ultimoEsito();
        $this->assertNotNull($esito);
        $this->assertSame('ok', $esito->stato);
        $this->assertSame(1, $esito->creati);
    }

    public function test_tipologia_torna_orfana_dopo_disattivazione(): void
    {
        $u = $this->utenteLdap('unico');
        $u->tipologieGestite()->sync([1]);
        $this->membro('altro', ['PRONTOPA_OPERAI']);

        $this->sync();

        $this->assertTrue(app(CoperturaTipologie::class)->isOrfana(1));
        $this->assertSame(1, $u->fresh()->tipologieGestite()->count(), 'tipologie conservate per il ritorno');
    }

    public function test_un_giro_alla_volta(): void
    {
        $this->membro('m.rossi');
        $lock = Cache::lock('ldap-sync', 600);
        $lock->get();

        $esito = $this->sync();

        $this->assertSame('fallita', $esito->stato);
        $this->assertStringContainsString('già in corso', (string) $esito->motivo);
        $lock->release();
    }

    public function test_comando_notturno_fallito_avvisa_admin(): void
    {
        $admin = $this->utenteLdap('capo', [], 'admin');
        $this->dir->nonDisponibile = true;

        $this->artisan('ldap:sincronizza')->assertFailed();

        Notification::assertSentTo($admin, SyncAdFallitaNotification::class);
    }

    public function test_errore_imprevisto_salva_fallita_e_avvisa(): void
    {
        $admin = $this->utenteLdap('capo', [], 'admin');
        $this->dir->errore = new \ErrorException('ldap_search(): Search: Operations error');

        $this->artisan('ldap:sincronizza')->assertFailed();

        $esito = app(SincronizzaUtentiAd::class)->ultimoEsito();
        $this->assertNotNull($esito);
        $this->assertSame('fallita', $esito->stato);
        $this->assertStringContainsString('Operations error', (string) $esito->motivo);
        $this->assertTrue((bool) $admin->fresh()->attivo);
        Notification::assertSentTo($admin, SyncAdFallitaNotification::class);
    }

    public function test_comando_spento_senza_configurazione(): void
    {
        $this->dir->sync = false;

        $this->artisan('ldap:sincronizza')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
