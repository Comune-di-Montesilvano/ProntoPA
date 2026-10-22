<?php

namespace App\Services\Backup;

use App\Models\Impostazione;
use Database\Seeders\AllineamentoSeeder;
use FilesystemIterator;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Sostituisce DB e allegati con il contenuto di un backup. L'archivio viene
 * estratto e verificato prima di toccare qualsiasi cosa; dopo dropAllTables
 * ogni errore riporta allo stato precedente (pre-ripristino) o a un DB vuoto.
 */
class RipristinoService
{
    private const NIENTE_TOCCATO = 'Ripristino non eseguito, nessun dato modificato: ';

    public function __construct(
        private readonly DumpDatabase $dump,
        private readonly ArchivioBackup $archivio,
        private readonly SegretiCifrati $segreti,
        private readonly BackupService $backup,
        private readonly CatalogoBackup $catalogo,
    ) {}

    public function anteprima(string $zip, #[\SensitiveParameter] string $passphrase): Anteprima
    {
        $this->archivio->verifica($zip, $passphrase);
        $manifest = $this->archivio->manifest($zip, $passphrase);
        $this->validaManifest($manifest);

        $sconosciute = array_values(array_diff($manifest['migrazioni'], array_keys($this->fileMigrazioni())));
        $vuota = $this->installazioneVuota();

        $bloccante = null;
        if ($sconosciute !== []) {
            $bloccante = 'Il backup è più recente di questa installazione: contiene migrazioni sconosciute ('
                .implode(', ', $sconosciute).'). Aggiorna ProntoPA alla versione del backup, poi riprova.';
        } elseif (! $vuota && $this->passphraseLocale() === null) {
            $bloccante = "Imposta BACKUP_PASSPHRASE prima di ripristinare: l'installazione contiene dati e "
                .'senza passphrase non si può salvare il backup pre-ripristino dello stato attuale.';
        }

        return new Anteprima(
            $manifest,
            $sconosciute === [],
            $sconosciute,
            $vuota,
            hash_equals(substr(hash('sha256', (string) config('app.key')), 0, 12), (string) ($manifest['app_key_impronta'] ?? '')),
            rtrim((string) ($manifest['app_url'] ?? ''), '/') === rtrim((string) config('app.url'), '/'),
            $bloccante,
        );
    }

    /** Non lancia per errori operativi: l'esito finisce sempre in StatoRipristino. */
    public function esegui(string $zip, #[\SensitiveParameter] string $passphrase, string $token): void
    {
        StatoRipristino::fase($token, 'verifica');

        $lock = Cache::lock('backup', 7200);
        try {
            if (! $lock->get()) {
                throw new BackupInCorso('un backup o un ripristino è già in corso, riprova più tardi.');
            }
            [$esito, $messaggio] = $this->eseguiConLock($zip, $passphrase, $token);
        } catch (BackupInCorso|BackupNonLeggibile|BackupNonConfigurato $e) {
            [$esito, $messaggio] = [false, self::NIENTE_TOCCATO.$e->getMessage()];
        } catch (Throwable $e) {
            // Qui arrivano solo errori prima di dropAllTables: dopo, ci pensa il rollback.
            report($e);
            [$esito, $messaggio] = [false, self::NIENTE_TOCCATO.$e->getMessage()];
        } finally {
            // Il lock Redis sta sulla lock_connection (DB 0): sopravvive a Cache::flush(), il rilascio è reale.
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }

        StatoRipristino::fine($token, $esito, $messaggio);
    }

    /** @return array{bool,string} */
    private function eseguiConLock(string $zip, #[\SensitiveParameter] string $passphrase, string $token): array
    {
        $anteprima = $this->anteprima($zip, $passphrase);
        if ($anteprima->bloccante !== null) {
            return [false, self::NIENTE_TOCCATO.$anteprima->bloccante];
        }
        $this->controllaCartelle();

        $tmp = $this->catalogo->cartella().'/tmp/ripristino-'.$token;
        try {
            $this->fase($token, 'estrazione');
            $estratto = $this->estraiVerificato($zip, $passphrase, $tmp, $anteprima->manifest);

            $this->fase($token, 'manutenzione');
            try {
                $this->giu();
                // Dopo il down: il pre-ripristino deve contenere anche le ultime scritture.
                $pre = null;
                if (! $this->installazioneVuota()) {
                    $this->fase($token, 'pre-ripristino');
                    $pre = $this->backup->creaSenzaLock('pre-ripristino')->nome;
                    StatoRipristino::preRipristino($token, $pre);
                }

                return $this->sostituisciConRollback($estratto, $token, basename($zip), $pre);
            } finally {
                $this->su();
            }
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    /**
     * @param  array{dir:string,manifest:array<string,mixed>,segreti:list<array<string,mixed>>}  $estratto
     * @return array{bool,string}
     */
    private function sostituisciConRollback(array $estratto, string $token, string $nome, ?string $pre): array
    {
        try {
            $this->sostituisci($estratto, $token);
        } catch (Throwable $e) {
            report($e);
            $motivo = 'Ripristino da '.$nome.' non riuscito ('.$e->getMessage().')';

            try {
                $this->fase($token, 'rollback');
                if ($pre !== null) {
                    $this->ripristinaPre($pre, $token);

                    return [false, "$motivo. Lo stato precedente è stato ripristinato automaticamente dal backup $pre."];
                }
                $this->reinizializza();

                return [false, "$motivo. L'installazione era vuota: il database è stato reinizializzato vuoto, gli allegati possono essere stati sostituiti in parte."];
            } catch (Throwable $r) {
                report($r);
                $cosa = $pre !== null ? "Ripristina a mano il backup $pre." : 'Ripristina un backup valido.';

                return [false, "$motivo e anche il ripristino automatico dello stato precedente è fallito ({$r->getMessage()}): il database può essere incoerente. $cosa"];
            }
        }

        return [true, "Ripristino completato dal backup $nome. "
            .($pre !== null ? "Lo stato precedente è salvato nel backup $pre. " : '')
            .'Tutte le sessioni sono state chiuse: rientra con le credenziali di dominio.'];
    }

    /** Una sola volta e senza nuovo pre-ripristino: niente ricorsione. */
    private function ripristinaPre(string $pre, string $token): void
    {
        $passphrase = (string) $this->passphraseLocale();
        $zip = $this->catalogo->percorso($pre);
        $tmp = $this->catalogo->cartella().'/tmp/ripristino-'.$token.'-rollback';
        try {
            $this->archivio->verifica($zip, $passphrase);
            $manifest = $this->archivio->manifest($zip, $passphrase);
            $this->validaManifest($manifest);
            $this->sostituisci($this->estraiVerificato($zip, $passphrase, $tmp, $manifest), $token);
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    private function reinizializza(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->artisan('db:seed', ['--class' => AllineamentoSeeder::class, '--force' => true]);
        Cache::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param array{dir:string,manifest:array<string,mixed>,segreti:list<array<string,mixed>>} $estratto */
    private function sostituisci(array $estratto, string $token): void
    {
        $this->fase($token, 'database');
        Schema::dropAllTables();
        $this->ricostruisciSchema($estratto['manifest']['migrazioni']);
        // Alcune migration inseriscono dati di riferimento: sono già nel dump, l'import li riscrive.
        Schema::withoutForeignKeyConstraints(function () use ($estratto) {
            foreach (array_keys($estratto['manifest']['conteggi']) as $tabella) {
                DB::table($tabella)->delete();
            }
        });
        $this->dump->importa($estratto['dir'].'/db', $estratto['manifest']['conteggi']);

        $this->fase($token, 'allegati');
        $this->sostituisciStorage($estratto['dir'].'/storage/app');

        // Backup di una release precedente: porta lo schema e i dati di riferimento a quelli attuali.
        $this->fase($token, 'aggiornamento');
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('db:seed', ['--class' => AllineamentoSeeder::class, '--force' => true]);
        $this->segreti->applica($estratto['segreti']);
        Impostazione::set('ripristino_id', (string) Str::uuid());
        Cache::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Lo schema esatto del backup, così i JSONL combaciano; le migration successive girano dopo l'import. */
    private function ricostruisciSchema(array $nomi): void
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $repository = $migrator->getRepository();
        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        $file = $this->fileMigrazioni();
        $percorsi = [];
        foreach ($nomi as $nome) {
            $percorsi[] = $file[$nome] ?? throw new RuntimeException("Migrazione $nome assente nell'applicazione.");
        }
        $migrator->requireFiles($percorsi);
        $migrator->runPending($percorsi);
    }

    /** @return array<string,string> nome => percorso */
    private function fileMigrazioni(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        return $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array{dir:string,manifest:array<string,mixed>,segreti:list<array<string,mixed>>}
     */
    private function estraiVerificato(string $zip, #[\SensitiveParameter] string $passphrase, string $tmp, array $manifest): array
    {
        File::deleteDirectory($tmp);
        $this->catalogo->temporanea(basename($tmp));
        $this->archivio->estrai($zip, $passphrase, $tmp);

        // Fa fede ciò che è stato estratto: il file potrebbe essere cambiato dopo la verifica.
        $estratto = json_decode((string) @file_get_contents($tmp.'/manifest.json'), true);
        if ($estratto !== $manifest) {
            throw new BackupNonLeggibile('il backup è cambiato durante il ripristino, riprova.');
        }
        foreach ($manifest['checksum'] as $nome => $hash) {
            $file = $tmp.'/'.$nome;
            if (! is_file($file) || ! hash_equals((string) $hash, (string) hash_file('sha256', $file))) {
                throw new BackupNonLeggibile("checksum non valido per $nome dopo l'estrazione.");
            }
        }

        $segreti = json_decode((string) file_get_contents($tmp.'/segreti.json'), true);
        if (! is_array($segreti) || ! array_is_list($segreti)) {
            throw new BackupNonLeggibile('segreti del backup illeggibili.');
        }
        // Solo le colonne cifrate note: un archivio alterato non deve poter scrivere altrove.
        $ammesse = array_map(fn ($d) => $d['tabella'].'.'.$d['colonna'], (array) config('backup.colonne_cifrate'));
        $segreti = array_values(array_filter($segreti, fn ($s) => is_array($s)
            && in_array(($s['tabella'] ?? '').'.'.($s['colonna'] ?? ''), $ammesse, true)
            && isset($s['valore']) && (isset($s['chiave']) || isset($s['id']))));

        return ['dir' => $tmp, 'manifest' => $manifest, 'segreti' => $segreti];
    }

    /** I nomi del manifest diventano percorsi e tabelle: l'archivio caricato non è fidato. */
    private function validaManifest(array $m): void
    {
        $nome = '/^[A-Za-z0-9_]+$/';
        $migrazioni = $m['migrazioni'] ?? null;
        $conteggi = $m['conteggi'] ?? null;
        $checksum = $m['checksum'] ?? null;
        if (! is_array($migrazioni) || $migrazioni === [] || ! array_is_list($migrazioni)
            || array_filter($migrazioni, fn ($n) => ! is_string($n) || ! preg_match($nome, $n)) !== []
            || ! is_array($conteggi) || ! is_array($checksum) || ! isset($checksum['segreti.json'])) {
            throw new BackupNonLeggibile('manifest del backup incompleto o non valido.');
        }
        foreach ($conteggi as $tabella => $n) {
            if (! is_string($tabella) || ! preg_match($nome, $tabella) || ! is_int($n) || $n < 0
                || in_array($tabella, (array) config('backup.tabelle_escluse'), true) || ! isset($checksum["db/$tabella.jsonl"])) {
                throw new BackupNonLeggibile("tabella $tabella non valida nel manifest del backup.");
            }
        }
        foreach (array_keys($checksum) as $voce) {
            if (! preg_match('#^(segreti\.json|db/[A-Za-z0-9_]+\.jsonl)$#', (string) $voce)) {
                throw new BackupNonLeggibile("voce $voce non valida nel manifest del backup.");
            }
        }
    }

    /** Svuota storage_root (tranne quarantena) e copia gli allegati del backup. Non segue mai i symlink. */
    private function sostituisciStorage(string $sorgente): void
    {
        $radice = rtrim((string) config('backup.storage_root'), '/\\');
        File::ensureDirectoryExists($radice);
        foreach (new FilesystemIterator($radice, FilesystemIterator::SKIP_DOTS) as $voce) {
            /** @var \SplFileInfo $voce */
            if ($voce->getFilename() === 'quarantena') {
                continue;
            }
            if ($voce->getFilename() === 'public' && $voce->isDir() && ! $voce->isLink()) {
                $this->svuota($voce->getPathname()); // mountpoint in prod: si svuota, non si rimuove

                continue;
            }
            $this->rimuovi($voce->getPathname());
        }

        if (! is_dir($sorgente)) {
            return;
        }
        $inizio = strlen(rtrim($sorgente, '/\\')) + 1;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sorgente, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $voce) {
            $relativo = str_replace('\\', '/', substr($voce->getPathname(), $inizio));
            if ($relativo === 'quarantena' || str_starts_with($relativo, 'quarantena/')) {
                continue;
            }
            $dest = $radice.'/'.$relativo;
            if ($voce->isDir()) {
                File::ensureDirectoryExists($dest);
            } elseif (! copy($voce->getPathname(), $dest)) {
                throw new RuntimeException("copia dell'allegato $relativo non riuscita.");
            }
        }
    }

    private function svuota(string $dir): void
    {
        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $voce) {
            $this->rimuovi($voce->getPathname());
        }
    }

    private function rimuovi(string $percorso): void
    {
        if (is_link($percorso) || ! is_dir($percorso)) {
            if (! unlink($percorso)) {
                throw new RuntimeException("rimozione di $percorso non riuscita.");
            }

            return;
        }
        $this->svuota($percorso);
        rmdir($percorso);
    }

    /** Se i backup stessero dentro storage_root, la sostituzione degli allegati li cancellerebbe. */
    private function controllaCartelle(): void
    {
        $radice = realpath((string) config('backup.storage_root'));
        $backup = realpath($this->catalogo->cartella());
        if ($radice !== false && $backup !== false
            && ($backup === $radice || str_starts_with($backup.DIRECTORY_SEPARATOR, $radice.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('BACKUP_PATH non può stare dentro storage/app: il ripristino cancellerebbe i backup.');
        }
    }

    /** Un aggiornamento di avanzamento non riuscito non deve interrompere il ripristino a metà. */
    private function fase(string $token, string $fase): void
    {
        try {
            StatoRipristino::fase($token, $fase);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function giu(): void
    {
        if (Artisan::call('down', ['--retry' => 30]) !== 0) {
            throw new RuntimeException("impossibile mettere l'applicazione in manutenzione: ".trim(Artisan::output()));
        }
    }

    /** In ogni percorso dopo il down: l'app non deve mai restare in manutenzione. */
    private function su(): void
    {
        try {
            if (Artisan::call('up') !== 0) {
                report(new RuntimeException('Uscita dalla manutenzione non riuscita: '.trim(Artisan::output())));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param array<string,mixed> $parametri */
    private function artisan(string $comando, array $parametri): void
    {
        if (Artisan::call($comando, $parametri) !== 0) {
            throw new RuntimeException("$comando non riuscito: ".trim(Artisan::output()));
        }
    }

    private function installazioneVuota(): bool
    {
        // Tabelle assenti (DB rimasto incoerente): non c'è nulla da salvare, il ripristino deve poter procedere.
        if (! Schema::hasTable('segnalazioni') || ! Schema::hasTable('users')) {
            return true;
        }

        return DB::table('segnalazioni')->count() === 0 && DB::table('users')->count() <= 1;
    }

    private function passphraseLocale(): ?string
    {
        $p = config('backup.passphrase');

        return is_string($p) && $p !== '' ? $p : null;
    }
}
