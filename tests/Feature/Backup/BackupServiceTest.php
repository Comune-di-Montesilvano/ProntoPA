<?php

namespace Tests\Feature\Backup;

use App\Models\User;
use App\Notifications\BackupFallitoNotification;
use App\Services\Backup\ArchivioBackup;
use App\Services\Backup\BackupInCorso;
use App\Services\Backup\BackupNonConfigurato;
use App\Services\Backup\BackupProtetto;
use App\Services\Backup\BackupService;
use App\Services\Backup\CatalogoBackup;
use App\Services\Backup\DumpDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

class BackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/bks-'.uniqid('', true);
        mkdir($this->base.'/backups', 0777, true);
        mkdir($this->base.'/app/quarantena', 0777, true);
        mkdir($this->base.'/app/public', 0777, true);
        file_put_contents($this->base.'/app/public/a.txt', 'allegato');
        file_put_contents($this->base.'/app/quarantena/x', 'virus');
        config([
            'backup.path' => $this->base.'/backups',
            'backup.storage_root' => $this->base.'/app',
            'backup.passphrase' => 'test-pass',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    /** @return list<string> */
    private function zips(): array
    {
        return array_map('basename', glob($this->base.'/backups/*.zip') ?: []);
    }

    public function test_crea_backup_verificato(): void
    {
        $info = app(BackupService::class)->crea('manuale');

        $this->assertMatchesRegularExpression('/^prontopa-\d{8}-\d{6}-dev-manuale\.zip$/', $info->nome);
        $this->assertSame([$info->nome], $this->zips());
        $this->assertTrue($info->verificato);

        $sidecar = json_decode(file_get_contents($this->base.'/backups/'.$info->nome.'.json'), true);
        $this->assertTrue($sidecar['verificato']);
        $this->assertSame([], glob($this->base.'/backups/tmp/*') ?: []);

        $manifest = app(ArchivioBackup::class)->manifest($this->base.'/backups/'.$info->nome, 'test-pass');
        $this->assertNotEmpty($manifest['migrazioni']);
        $this->assertArrayNotHasKey('app_key', $manifest);
        $this->assertSame(12, strlen($manifest['app_key_impronta']));
        $this->assertSame('manuale', $manifest['tipo']);
        $this->assertSame(1, $manifest['file_storage']['numero']);
    }

    public function test_tipo_non_valido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(BackupService::class)->crea('boh');
    }

    public function test_senza_passphrase_niente_file(): void
    {
        config(['backup.passphrase' => null]);

        try {
            app(BackupService::class)->crea('manuale');
            $this->fail('atteso BackupNonConfigurato');
        } catch (BackupNonConfigurato) {
            $this->assertSame([], $this->zips());
        }
    }

    public function test_secondo_backup_concorrente_rinuncia(): void
    {
        $lock = Cache::lock('backup', 10);
        $this->assertTrue($lock->get());

        try {
            app(BackupService::class)->crea('manuale');
            $this->fail('atteso BackupInCorso');
        } catch (BackupInCorso) {
            $this->assertSame([], $this->zips());
        } finally {
            $lock->release();
        }
    }

    public function test_fallimento_notifica_admin_e_non_pulisce(): void
    {
        Notification::fake();
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $inattivo = User::factory()->create(['attivo' => false]);
        $inattivo->assignRole('admin');

        // Backup vecchi preesistenti: la retention ne eliminerebbe uno, se girasse.
        $vecchio = 'prontopa-20250101-020000-dev-notturno.zip';
        file_put_contents($this->base.'/backups/'.$vecchio, 'x');
        $nuovo = 'prontopa-20250102-020000-dev-notturno.zip';
        file_put_contents($this->base.'/backups/'.$nuovo, 'x');

        $this->app->bind(DumpDatabase::class, fn () => new class extends DumpDatabase
        {
            public function esporta(string $dir): array
            {
                throw new \RuntimeException('disco pieno');
            }
        });

        try {
            app(BackupService::class)->crea('notturno');
            $this->fail('atteso fallimento');
        } catch (\RuntimeException $e) {
            $this->assertSame('disco pieno', $e->getMessage());
        }

        Notification::assertSentTo($admin, BackupFallitoNotification::class);
        Notification::assertNotSentTo($inattivo, BackupFallitoNotification::class);
        $this->assertContains($vecchio, $this->zips());
        $this->assertContains($nuovo, $this->zips());

        $falliti = app(CatalogoBackup::class)->elenco()->where('verificato', false)->where('errore', 'disco pieno');
        $this->assertCount(1, $falliti);
        $this->assertSame(0, $falliti->first()->dimensione);
        $this->assertSame([], glob($this->base.'/backups/tmp/*') ?: []);
    }

    public function test_storage_esclude_quarantena_e_log(): void
    {
        $logs = storage_path('logs');
        @mkdir($logs, 0777, true);
        file_put_contents($logs.'/y.log', 'riga');

        $info = app(BackupService::class)->crea('manuale');

        $za = new ZipArchive;
        $za->open($this->base.'/backups/'.$info->nome);
        $nomi = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $nomi[] = $za->getNameIndex($i);
        }
        $za->close();

        $this->assertContains('storage/app/public/a.txt', $nomi);
        $this->assertSame([], array_filter($nomi, fn ($n) => str_contains($n, 'quarantena') || str_contains($n, 'y.log')));
    }

    public function test_catalogo_rifiuta_nomi_pericolosi(): void
    {
        $cat = app(CatalogoBackup::class);
        foreach (['../x.zip', 'prontopa-a/b.zip', 'altro.zip', 'prontopa-a..b.zip', 'prontopa-a\\b.zip', 'prontopa-a.txt'] as $nome) {
            try {
                $cat->percorso($nome);
                $this->fail("accettato $nome");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_elimina_rifiuta_ultimo_pre_ripristino_protetto(): void
    {
        $nome = 'prontopa-20260101-020000-dev-pre-ripristino.zip';
        file_put_contents($this->base.'/backups/'.$nome, 'x');
        // Notturno più recente ma senza sidecar (non verificato): non toglie la protezione.
        file_put_contents($this->base.'/backups/prontopa-20260102-020000-dev-notturno.zip', 'x');
        $cat = app(CatalogoBackup::class);

        $this->assertSame('pre-ripristino', $cat->elenco()->last()->tipo);
        $this->expectException(BackupProtetto::class);
        try {
            $cat->elimina($nome);
        } finally {
            $this->assertFileExists($this->base.'/backups/'.$nome);
        }
    }

    public function test_elimina_rimuove_zip_e_sidecar(): void
    {
        $info = app(BackupService::class)->crea('manuale');
        app(CatalogoBackup::class)->elimina($info->nome);

        $this->assertSame([], glob($this->base.'/backups/prontopa-*') ?: []);
    }

    public function test_in_allarme(): void
    {
        $cat = app(CatalogoBackup::class);
        $this->assertTrue($cat->inAllarme(CarbonImmutable::parse('2026-10-22 03:00')));

        $info = app(BackupService::class)->crea('manuale');
        $this->assertFalse($cat->inAllarme($info->creatoIl->addHours(35)));
        $this->assertTrue($cat->inAllarme($info->creatoIl->addHours(37)));
    }

    public function test_job_va_sulla_coda_lunga(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        config(['backup.connessione_coda' => 'redis-lunghi']);

        \App\Jobs\CreaBackup::dispatch('manuale');

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\CreaBackup::class, fn ($j) => $j->connection === 'redis-lunghi' && $j->queue === 'lunghi');
    }

    public function test_errore_nella_retention_non_fa_fallire_il_backup(): void
    {
        $this->app->bind(\App\Services\Backup\Retention::class, fn () => new class extends \App\Services\Backup\Retention
        {
            public function daEliminare(\Illuminate\Support\Collection $backup, CarbonImmutable $ora): array
            {
                throw new \RuntimeException('retention rotta');
            }
        });

        $info = app(BackupService::class)->crea('manuale');

        $this->assertTrue($info->verificato);
        $this->assertSame([$info->nome], $this->zips());
    }

    public function test_sidecar_spazzatura_ignorato(): void
    {
        $info = app(BackupService::class)->crea('manuale');
        file_put_contents($this->base.'/backups/prontopa-junk.zip.json', '{"nome":"prontopa-junk.zip"}');
        file_put_contents($this->base.'/backups/prontopa-bad.zip.json', 'non json');
        file_put_contents($this->base.'/backups/prontopa-altro.zip.json', json_encode(['nome' => '../x.zip', 'tipo' => 'manuale', 'creato_il' => '2026-01-01T00:00:00+00:00']));

        $this->assertSame([$info->nome], app(CatalogoBackup::class)->elenco()->pluck('nome')->all());
    }
    public function test_temporanei_vecchi_rimossi_recenti_tenuti(): void
    {
        $tmp = $this->base.'/backups/tmp';
        $stato = $this->base.'/backups/stato';
        mkdir($tmp.'/abc123/db', 0777, true);
        file_put_contents($tmp.'/abc123/db/users.jsonl', '{"id":1}');
        mkdir($tmp.'/.ricifra-vecchia', 0777, true);
        file_put_contents($tmp.'/.ricifra-vecchia/segreti.json', '[]');
        mkdir($tmp.'/ripristino-'.str_repeat('a', 40).'/db', 0777, true);
        file_put_contents($tmp.'/upload-vecchio.part', 'x');
        file_put_contents($tmp.'/upload-vecchio.part.nuovo', 'x');
        mkdir($tmp.'/fresca', 0777, true);
        file_put_contents($tmp.'/upload-fresco.part', 'y');
        mkdir($stato, 0777, true);
        file_put_contents($stato.'/vecchio.json', '{}');
        file_put_contents($stato.'/recente.json', '{}');
        touch($stato.'/vecchio.json', time() - 31 * 86400);
        touch($stato.'/recente.json', time() - 29 * 86400);

        $vecchi = ['abc123', '.ricifra-vecchia', 'ripristino-'.str_repeat('a', 40), 'upload-vecchio.part', 'upload-vecchio.part.nuovo'];
        foreach ($vecchi as $v) {
            touch($tmp.'/'.$v, time() - 4 * 3600);
        }
        touch($tmp.'/fresca', time() - 2 * 3600);

        app(BackupService::class)->applicaRetention();

        foreach ($vecchi as $v) {
            $this->assertFileDoesNotExist($tmp.'/'.$v, $v);
        }
        $this->assertDirectoryExists($tmp.'/fresca');
        $this->assertFileExists($tmp.'/upload-fresco.part');
        $this->assertFileDoesNotExist($stato.'/vecchio.json');
        $this->assertFileExists($stato.'/recente.json');
    }

    public function test_cartella_temporanea_del_backup_solo_proprietario(): void
    {
        $dump = new class extends DumpDatabase
        {
            public ?int $permessi = null;

            public function esporta(string $dir): array
            {
                $this->permessi = fileperms(dirname($dir)) & 0777;

                return parent::esporta($dir);
            }
        };
        $this->app->instance(DumpDatabase::class, $dump);

        app(BackupService::class)->crea('manuale');

        $this->assertSame(0700, $dump->permessi);
    }

    public function test_marcatore_in_corso_durante_il_backup(): void
    {
        $cat = app(CatalogoBackup::class);
        $dump = new class extends DumpDatabase
        {
            /** @var array<string,mixed>|null */
            public ?array $marcatore = null;

            public function esporta(string $dir): array
            {
                $this->marcatore = app(CatalogoBackup::class)->inCorso();

                return parent::esporta($dir);
            }
        };
        $this->app->instance(DumpDatabase::class, $dump);

        app(BackupService::class)->crea('notturno');

        $this->assertSame('notturno', $dump->marcatore['tipo'] ?? null);
        $this->assertSame('in corso', $dump->marcatore['fase'] ?? null);
        $this->assertNull($cat->inCorso());
    }

    public function test_marcatore_rimosso_anche_se_il_backup_fallisce(): void
    {
        $this->app->bind(DumpDatabase::class, fn () => new class extends DumpDatabase
        {
            public function esporta(string $dir): array
            {
                throw new \RuntimeException('disco pieno');
            }
        });

        try {
            app(BackupService::class)->crea('manuale');
        } catch (\RuntimeException) {
        }

        $this->assertNull(app(CatalogoBackup::class)->inCorso());
    }

    public function test_marcatore_vecchio_ignorato_e_ripulito(): void
    {
        $cat = app(CatalogoBackup::class);
        $cat->segnaInCorso('manuale', 'in coda');
        $file = $this->base.'/backups/stato/'.CatalogoBackup::MARCATORE;
        $this->assertFileExists($file);
        touch($file, time() - 4 * 3600);

        $this->assertNull($cat->inCorso());
        $cat->pulisciTemporanei();
        $this->assertFileDoesNotExist($file);
    }

    public function test_job_che_trova_lock_occupato_toglie_solo_il_proprio_marcatore(): void
    {
        $cat = app(CatalogoBackup::class);
        $lock = Cache::lock('backup', 10);
        $this->assertTrue($lock->get());
        try {
            $cat->segnaInCorso('manuale', 'in coda');
            app()->call([new \App\Jobs\CreaBackup('manuale'), 'handle']);
            $this->assertNull($cat->inCorso());

            // Il marcatore di un backup già partito appartiene a quello.
            $cat->segnaInCorso('notturno', 'in corso');
            app()->call([new \App\Jobs\CreaBackup('manuale'), 'handle']);
            $this->assertSame('notturno', $cat->inCorso()['tipo'] ?? null);
        } finally {
            $lock->release();
        }
    }

    public function test_allarme_ignora_i_caricati(): void
    {
        $cat = app(CatalogoBackup::class);
        $ora = CarbonImmutable::now();
        $nome = 'prontopa-'.$ora->format('Ymd-His').'-dev-caricato.zip';
        file_put_contents($this->base.'/backups/'.$nome, 'x');
        $cat->salvaInfo(new \App\Services\Backup\BackupInfo($nome, 'caricato', $ora, 'dev', 1, true, null, null));

        $this->assertTrue($cat->inAllarme($ora));
    }
}
