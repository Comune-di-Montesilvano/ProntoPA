<?php

namespace Tests\Feature\Backup;

use App\Jobs\EseguiRipristino;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Backup\ArchivioBackup;
use App\Services\Backup\BackupNonLeggibile;
use App\Services\Backup\BackupService;
use App\Services\Backup\DumpDatabase;
use App\Services\Backup\RipristinoService;
use App\Services\Backup\SegretiCifrati;
use App\Services\Backup\StatoRipristino;
use Database\Seeders\AllineamentoSeeder;
use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Niente RefreshDatabase: il ripristino fa DDL (dropAllTables) e spegne le FK,
 * cose che dentro una transazione sqlite non si comportano come in produzione.
 */
class RipristinoServiceTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        // La manutenzione su file scriverebbe storage/framework/down del container dev. Store
        // dedicato: Cache::flush() svuota solo quello di default, come in prod con il driver file.
        config([
            'cache.stores.manutenzione' => ['driver' => 'array'],
            'app.maintenance.driver' => 'cache',
            'app.maintenance.store' => 'manutenzione',
        ]);

        $this->base = sys_get_temp_dir().'/rip-'.uniqid('', true);
        mkdir($this->base.'/backups', 0777, true);
        mkdir($this->base.'/app/public', 0777, true);
        mkdir($this->base.'/app/quarantena', 0777, true);
        config([
            'backup.path' => $this->base.'/backups',
            'backup.storage_root' => $this->base.'/app',
            'backup.passphrase' => 'test-pass',
        ]);

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function allinea(): void
    {
        Artisan::call('db:seed', ['--class' => AllineamentoSeeder::class, '--force' => true]);
    }

    private function servizio(): RipristinoService
    {
        return app(RipristinoService::class);
    }

    /** @return list<string> */
    private function zips(string $tipo): array
    {
        return array_map('basename', glob($this->base."/backups/*-$tipo.zip") ?: []);
    }

    /** Riscrive l'archivio con un manifest modificato (scrivi() ricalcola i checksum). */
    private function rifai(string $zip, callable $modifica, string $pass = 'test-pass'): string
    {
        $dir = $this->base.'/rifai-'.uniqid();
        $archivio = new ArchivioBackup;
        $archivio->estrai($zip, $pass, $dir);
        $manifest = json_decode(file_get_contents($dir.'/manifest.json'), true);
        unset($manifest['checksum']);
        $nuovo = $this->base.'/backups/prontopa-20260101-000000-dev-caricato.zip';
        $archivio->scrivi(
            $nuovo, $pass, $modifica($manifest), $dir.'/db', $dir.'/storage/app',
            json_decode(file_get_contents($dir.'/segreti.json'), true),
        );
        File::deleteDirectory($dir);

        return $nuovo;
    }

    public function test_ripristino_su_installazione_vuota(): void
    {
        $this->allinea();
        User::factory()->count(3)->create();
        Segnalazione::factory()->count(2)->create();
        Impostazione::set('ente_nome', 'Comune A');
        file_put_contents($this->base.'/app/public/a.txt', 'allegato');

        $info = app(BackupService::class)->crea('manuale');
        $zip = $this->base.'/backups/'.$info->nome;
        $manifest = (new ArchivioBackup)->manifest($zip, 'test-pass');

        // Installazione nuova: solo l'admin entrato via AD, passphrase locale non ancora impostata.
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->allinea();
        User::factory()->create();
        Impostazione::set('ente_nome', 'Nuovo');
        unlink($this->base.'/app/public/a.txt');
        file_put_contents($this->base.'/app/public/vecchio.txt', 'da rimuovere');
        file_put_contents($this->base.'/app/radice.txt', 'da rimuovere');
        file_put_contents($this->base.'/app/quarantena/x', 'virus');
        config(['backup.passphrase' => null]);

        $anteprima = $this->servizio()->anteprima($zip, 'test-pass');
        $this->assertTrue($anteprima->installazioneVuota);
        $this->assertTrue($anteprima->compatibile);
        $this->assertNull($anteprima->bloccante);
        $this->assertTrue($anteprima->stessaAppKey);
        $this->assertTrue($anteprima->stessoAppUrl);

        $manutenzione = [];
        Event::listen(MaintenanceModeEnabled::class, function () use (&$manutenzione) {
            $manutenzione[] = 'giu';
        });
        Event::listen(MaintenanceModeDisabled::class, function () use (&$manutenzione) {
            $manutenzione[] = 'su';
        });

        $token = StatoRipristino::nuovoToken();
        $this->servizio()->esegui($zip, 'test-pass', $token);

        $stato = StatoRipristino::leggi($token);
        $this->assertTrue($stato['esito'], (string) $stato['messaggio']);
        $this->assertSame(['giu', 'su'], $manutenzione);
        foreach ($manifest['conteggi'] as $tabella => $n) {
            $query = DB::table($tabella);
            if ($tabella === 'impostazioni') {
                $query->where('chiave', '!=', 'ripristino_id');
            }
            $this->assertSame($n, $query->count(), "conteggio di $tabella");
        }
        $this->assertSame('Comune A', Impostazione::get('ente_nome'));
        $this->assertNotEmpty(Impostazione::get('ripristino_id'));
        $this->assertSame('allegato', file_get_contents($this->base.'/app/public/a.txt'));
        $this->assertFileDoesNotExist($this->base.'/app/public/vecchio.txt');
        $this->assertFileDoesNotExist($this->base.'/app/radice.txt');
        $this->assertFileExists($this->base.'/app/quarantena/x');
        $this->assertSame([], $this->zips('pre-ripristino'));
        $this->assertSame([], glob($this->base.'/backups/tmp/*') ?: []);
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_rifiuta_migrazioni_sconosciute(): void
    {
        $this->allinea();
        $zip = $this->base.'/backups/'.app(BackupService::class)->crea('manuale')->nome;
        $zip = $this->rifai($zip, function (array $m) {
            $m['migrazioni'][] = '2099_01_01_000000_futura';

            return $m;
        });

        $anteprima = $this->servizio()->anteprima($zip, 'test-pass');
        $this->assertFalse($anteprima->compatibile);
        $this->assertSame(['2099_01_01_000000_futura'], $anteprima->migrazioniSconosciute);
        $this->assertStringContainsString('più recente', (string) $anteprima->bloccante);

        // esegui non si fida della UI: rifiuta da solo, senza toccare nulla.
        Impostazione::set('ente_nome', 'Intatto');
        $token = StatoRipristino::nuovoToken();
        $this->servizio()->esegui($zip, 'test-pass', $token);
        $this->assertFalse(StatoRipristino::leggi($token)['esito']);
        $this->assertSame('Intatto', Impostazione::get('ente_nome'));
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_installazione_non_vuota_senza_passphrase_bloccata(): void
    {
        $this->allinea();
        Segnalazione::factory()->count(2)->create();
        $zip = $this->base.'/backups/'.app(BackupService::class)->crea('manuale')->nome;
        config(['backup.passphrase' => null]);

        $anteprima = $this->servizio()->anteprima($zip, 'test-pass');
        $this->assertFalse($anteprima->installazioneVuota);
        $this->assertNotNull($anteprima->bloccante);
        $this->assertStringContainsString('BACKUP_PASSPHRASE', $anteprima->bloccante);

        $token = StatoRipristino::nuovoToken();
        $this->servizio()->esegui($zip, 'test-pass', $token);
        $this->assertFalse(StatoRipristino::leggi($token)['esito']);
        $this->assertSame(2, Segnalazione::count());
    }

    public function test_backup_vecchio_migra_allo_schema_attuale(): void
    {
        // Backup reale prodotto da un'app "indietro di due release": l'ultima migration
        // crea tabelle (deleghe), la penultima aggiunge una colonna (plessi.fonte_dati).
        Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('plessi', 'fonte_dati'));
        $this->assertFalse(Schema::hasTable('deleghe'));
        $idIstituto = DB::table('istituti')->insertGetId(['descrizione' => 'IC Vecchio']);
        DB::table('plessi')->insert(['id_istituto' => $idIstituto, 'nome' => 'Plesso Vecchio']);
        User::factory()->create();

        $zip = $this->base.'/backups/'.app(BackupService::class)->crea('manuale')->nome;
        $manifest = (new ArchivioBackup)->manifest($zip, 'test-pass');
        $this->assertNotContains('2026_10_09_000001_create_deleghe_tables', $manifest['migrazioni']);
        $this->assertArrayNotHasKey('deleghe', $manifest['conteggi']);

        // L'app installata è aggiornata.
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->allinea();

        $token = StatoRipristino::nuovoToken();
        $this->servizio()->esegui($zip, 'test-pass', $token);

        $this->assertTrue(StatoRipristino::leggi($token)['esito'], (string) StatoRipristino::leggi($token)['messaggio']);
        $this->assertTrue(DB::table('migrations')->where('migration', '2026_10_09_000001_create_deleghe_tables')->exists());
        $this->assertTrue(Schema::hasTable('deleghe'));
        $plesso = DB::table('plessi')->where('nome', 'Plesso Vecchio')->first();
        $this->assertNotNull($plesso);
        $this->assertSame('manuale', $plesso->fonte_dati);
        $this->assertSame('IC Vecchio', DB::table('istituti')->value('descrizione'));
    }

    public function test_fallimento_a_meta_fa_rollback(): void
    {
        $this->allinea();
        Impostazione::set('ente_nome', 'Comune A');
        file_put_contents($this->base.'/app/public/solo-backup.txt', 'di A');
        $zip = $this->base.'/backups/'.app(BackupService::class)->crea('manuale')->nome;

        Impostazione::set('ente_nome', 'Comune B');
        Segnalazione::factory()->count(2)->create();
        unlink($this->base.'/app/public/solo-backup.txt');
        file_put_contents($this->base.'/app/public/b.txt', 'di B');

        // Fallisce DOPO la sostituzione degli allegati: il rollback deve rimettere anche quelli.
        $this->app->instance(SegretiCifrati::class, new class extends SegretiCifrati
        {
            public int $chiamate = 0;

            public function applica(array $segreti): void
            {
                if ($this->chiamate++ === 0) {
                    throw new \RuntimeException('Ricifratura simulata fallita');
                }
                parent::applica($segreti);
            }
        });

        $token = StatoRipristino::nuovoToken();
        $this->servizio()->esegui($zip, 'test-pass', $token);

        $stato = StatoRipristino::leggi($token);
        $pre = $this->zips('pre-ripristino');
        $this->assertCount(1, $pre);
        $this->assertFalse($stato['esito']);
        $this->assertStringContainsString('Ricifratura simulata fallita', $stato['messaggio']);
        $this->assertStringContainsString($pre[0], $stato['messaggio']);
        $this->assertStringContainsString('ripristinato automaticamente', $stato['messaggio']);
        $this->assertSame($pre[0], $stato['pre_ripristino']);
        $this->assertSame('Comune B', Impostazione::get('ente_nome'));
        $this->assertSame(2, Segnalazione::count());
        $this->assertSame('di B', file_get_contents($this->base.'/app/public/b.txt'));
        $this->assertFileDoesNotExist($this->base.'/app/public/solo-backup.txt');
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertSame([], glob($this->base.'/backups/tmp/*') ?: []);
    }

    public function test_job_fallito_riporta_su_e_indica_il_pre_ripristino(): void
    {
        $token = StatoRipristino::nuovoToken();
        StatoRipristino::fase($token, 'database');
        StatoRipristino::preRipristino($token, 'prontopa-20261022-010000-dev-pre-ripristino.zip');
        mkdir($this->base.'/backups/tmp/ripristino-'.$token.'/db', 0777, true);
        Artisan::call('down');
        $this->assertTrue(app()->isDownForMaintenance());

        (new EseguiRipristino($this->base.'/backups/x.zip', 'test-pass', $token))
            ->failed(new \RuntimeException('Worker terminato'));

        $stato = StatoRipristino::leggi($token);
        $this->assertFalse($stato['esito']);
        $this->assertStringContainsString('prontopa-20261022-010000-dev-pre-ripristino.zip', $stato['messaggio']);
        $this->assertStringContainsString('incoerente', $stato['messaggio']);
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertDirectoryDoesNotExist($this->base.'/backups/tmp/ripristino-'.$token);

        // Senza pre-ripristino noto: rimanda all'elenco.
        $altro = StatoRipristino::nuovoToken();
        StatoRipristino::fase($altro, 'estrazione');
        (new EseguiRipristino($this->base.'/backups/x.zip', 'test-pass', $altro))->failed(null);
        $this->assertStringContainsString('ultimo backup pre-ripristino in elenco', StatoRipristino::leggi($altro)['messaggio']);
    }

    public function test_fallimento_su_installazione_vuota_reinizializza(): void
    {
        $this->allinea();
        Segnalazione::factory()->count(2)->create();
        $zip = $this->base.'/backups/'.app(BackupService::class)->crea('manuale')->nome;
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->allinea();

        $this->app->instance(DumpDatabase::class, new class extends DumpDatabase
        {
            public function importa(string $dir, array $conteggi): void
            {
                throw new \RuntimeException('Import simulato fallito');
            }
        });

        $token = StatoRipristino::nuovoToken();
        $this->servizio()->esegui($zip, 'test-pass', $token);

        $this->assertFalse(StatoRipristino::leggi($token)['esito']);
        $this->assertStringContainsString('reinizializzato', StatoRipristino::leggi($token)['messaggio']);
        $this->assertSame([], $this->zips('pre-ripristino'));
        $this->assertSame(0, Segnalazione::count());
        $this->assertTrue(Schema::hasTable('deleghe'));
        $this->assertTrue(DB::table('impostazioni')->exists());
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_avvisa_app_key_e_url_diverse(): void
    {
        $this->allinea();
        $zip = $this->base.'/backups/'.app(BackupService::class)->crea('manuale')->nome;
        $zip = $this->rifai($zip, function (array $m) {
            $m['app_url'] = 'http://localhost';
            $m['app_key_impronta'] = substr(hash('sha256', 'base64:'.base64_encode(str_repeat('x', 32))), 0, 12);

            return $m;
        });
        config(['app.url' => 'https://prontopa.ente.example']);

        $anteprima = $this->servizio()->anteprima($zip, 'test-pass');
        $this->assertFalse($anteprima->stessaAppKey);
        $this->assertFalse($anteprima->stessoAppUrl);
        $this->assertNull($anteprima->bloccante);
    }

    public function test_archivio_danneggiato_non_tocca_nulla(): void
    {
        $this->allinea();
        Segnalazione::factory()->count(2)->create();
        $zip = $this->base.'/backups/'.app(BackupService::class)->crea('manuale')->nome;

        try {
            $this->servizio()->anteprima($zip, 'sbagliata');
            $this->fail('atteso BackupNonLeggibile');
        } catch (BackupNonLeggibile) {
        }

        $token = StatoRipristino::nuovoToken();
        $this->servizio()->esegui($zip, 'sbagliata', $token);
        $this->assertFalse(StatoRipristino::leggi($token)['esito']);
        $this->assertSame(2, Segnalazione::count());
        $this->assertSame([], $this->zips('pre-ripristino'));
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_lock_occupato_non_tocca_nulla(): void
    {
        $this->allinea();
        Segnalazione::factory()->count(2)->create();
        $zip = $this->base.'/backups/'.app(BackupService::class)->crea('manuale')->nome;
        $lock = Cache::lock('backup', 10);
        $this->assertTrue($lock->get());

        $token = StatoRipristino::nuovoToken();
        try {
            $this->servizio()->esegui($zip, 'test-pass', $token);
        } finally {
            $lock->release();
        }

        $stato = StatoRipristino::leggi($token);
        $this->assertFalse($stato['esito']);
        $this->assertStringContainsString('in corso', $stato['messaggio']);
        $this->assertSame(2, Segnalazione::count());
    }

    public function test_tabelle_di_altri_schemi_ignorate(): void
    {
        // In dev (e dove l'utente DB vede altri database, es. 'legacy' per l'import)
        // getTableListing() elenca anche le tabelle degli altri schemi.
        DB::statement("ATTACH DATABASE ':memory:' AS legacy");
        DB::statement('CREATE TABLE legacy.utenti (nome TEXT)');

        $tabelle = app(DumpDatabase::class)->tabelle();
        $this->assertContains('users', $tabelle);
        $this->assertNotContains('utenti', $tabelle);
    }

    public function test_token_stato_validato(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', StatoRipristino::nuovoToken());
        $this->assertNull(StatoRipristino::leggi(StatoRipristino::nuovoToken()));

        $this->expectException(\InvalidArgumentException::class);
        StatoRipristino::leggi('../../etc/passwd');
    }

    public function test_comando_ripristina(): void
    {
        $this->allinea();
        Impostazione::set('ente_nome', 'Comune A');
        $nome = app(BackupService::class)->crea('manuale')->nome;
        Impostazione::set('ente_nome', 'Comune B');

        $this->artisan('backup:ripristina', ['file' => $nome, '--passphrase' => 'Zq9-non-questa', '--no-interaction' => true])
            ->doesntExpectOutputToContain('Zq9-non-questa')
            ->assertExitCode(1);
        $this->assertSame('Comune B', Impostazione::get('ente_nome'));

        $this->artisan('backup:ripristina', ['file' => $nome, '--no-interaction' => true])
            ->expectsOutputToContain('Ripristino completato')
            ->assertExitCode(0);
        $this->assertSame('Comune A', Impostazione::get('ente_nome'));
    }
}
