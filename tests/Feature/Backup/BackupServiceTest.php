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
}
