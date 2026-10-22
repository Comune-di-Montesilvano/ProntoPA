<?php

namespace App\Services\Backup;

use App\Models\User;
use App\Notifications\BackupFallitoNotification;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class BackupService
{
    public function __construct(
        private readonly DumpDatabase $dump,
        private readonly ArchivioBackup $archivio,
        private readonly SegretiCifrati $segreti,
        private readonly CatalogoBackup $catalogo,
        private readonly Retention $retention,
    ) {}

    /** Prende il lock 'backup' senza attendere: se occupato rinuncia. */
    public function crea(string $tipo): BackupInfo
    {
        $this->validaTipo($tipo);

        $lock = Cache::lock('backup', 3600);
        if (! $lock->get()) {
            throw new BackupInCorso('Un backup o un ripristino è già in corso.');
        }

        try {
            return $this->creaSenzaLock($tipo);
        } finally {
            $lock->release();
        }
    }

    /** Per chi detiene già il lock (il ripristino): il lock non è rientrante. */
    public function creaSenzaLock(string $tipo): BackupInfo
    {
        $this->validaTipo($tipo);

        $passphrase = config('backup.passphrase');
        if (! is_string($passphrase) || $passphrase === '') {
            throw new BackupNonConfigurato('BACKUP_PASSPHRASE non impostata: il backup è disattivato.');
        }

        $inizio = CarbonImmutable::now();
        $nome = sprintf('prontopa-%s-%s-%s.zip', $inizio->format('Ymd-His'), $this->versione(), $tipo);
        $cartella = $this->catalogo->cartella();
        $tmp = $cartella.'/tmp/'.bin2hex(random_bytes(8));

        try {
            $this->catalogo->temporanea(basename($tmp));
            File::ensureDirectoryExists($tmp.'/db');
            $conteggi = $this->dump->esporta($tmp.'/db');

            $radice = (string) config('backup.storage_root');
            $zipTmp = $tmp.'/'.$nome;
            $this->archivio->scrivi(
                $zipTmp, $passphrase,
                $this->manifest($tipo, $inizio, $conteggi, $radice),
                $tmp.'/db', $radice, $this->segreti->estrai(),
            );
            $this->archivio->verifica($zipTmp, $passphrase);

            // Il file compare nell'elenco solo da completo e verificato.
            rename($zipTmp, $this->catalogo->percorso($nome));

            $info = new BackupInfo(
                $nome, $tipo, $inizio, $this->versione(),
                (int) filesize($this->catalogo->percorso($nome)), true, null,
                CarbonImmutable::now()->getTimestamp() - $inizio->getTimestamp(),
            );
            $this->catalogo->salvaInfo($info);
        } catch (Throwable $e) {
            $this->segnaFallito($nome, $tipo, $inizio, $e);
            throw $e;
        } finally {
            File::deleteDirectory($tmp);
        }

        if (in_array($tipo, ['notturno', 'manuale'], true)) {
            // Il backup e gia riuscito: un errore di pulizia non deve farlo risultare fallito.
            try {
                $this->applicaRetention();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $info;
    }

    /** @return list<string> nomi eliminati */
    public function applicaRetention(): array
    {
        $this->catalogo->pulisciTemporanei();
        $eliminati = $this->retention->daEliminare($this->catalogo->elenco(), CarbonImmutable::now());
        foreach ($eliminati as $nome) {
            try {
                $this->catalogo->elimina($nome);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $eliminati;
    }

    private function validaTipo(string $tipo): void
    {
        if (! in_array($tipo, CatalogoBackup::TIPI, true)) {
            throw new \InvalidArgumentException("Tipo di backup non valido: $tipo");
        }
    }

    private function versione(): string
    {
        return self::versionePerNomeFile((string) config('app.version'));
    }

    /** Versione utilizzabile nel nome file (anche per i backup caricati, che portano la versione del manifest). */
    public static function versionePerNomeFile(string $versione): string
    {
        return preg_replace('/[^A-Za-z0-9.]/', '_', $versione) ?: 'dev';
    }

    /**
     * @param  array<string,int>  $conteggi
     * @return array<string,mixed>
     */
    private function manifest(string $tipo, CarbonImmutable $inizio, array $conteggi, string $radice): array
    {
        $numero = 0;
        $byte = 0;
        if (is_dir($radice)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(rtrim($radice, '/\\')) + 1));
                if ($f->isFile() && $rel !== 'quarantena' && ! str_starts_with($rel, 'quarantena/')) {
                    $numero++;
                    $byte += $f->getSize();
                }
            }
        }

        return [
            'versione' => (string) config('app.version'),
            'creato_il' => $inizio->toIso8601String(),
            'app_url' => (string) config('app.url'),
            'app_key_impronta' => substr(hash('sha256', (string) config('app.key')), 0, 12),
            'migrazioni' => $this->dump->migrazioniApplicate(),
            'conteggi' => $conteggi,
            'file_storage' => ['numero' => $numero, 'byte' => $byte],
            'tipo' => $tipo,
        ];
    }

    /** Il sidecar rende visibile il fallimento anche senza zip (catalogo e allarme). */
    private function segnaFallito(string $nome, string $tipo, CarbonImmutable $inizio, Throwable $e): void
    {
        $info = new BackupInfo(
            $nome, $tipo, $inizio, $this->versione(), 0, false, $e->getMessage(),
            CarbonImmutable::now()->getTimestamp() - $inizio->getTimestamp(),
        );

        try {
            $this->catalogo->salvaInfo($info);
        } catch (Throwable $s) {
            report($s);
        }
        report($e);

        try {
            Notification::send(
                User::role('admin')->where('attivo', true)->get(),
                new BackupFallitoNotification($nome, $e->getMessage()),
            );
        } catch (Throwable $n) {
            report($n);
        }
    }
}
