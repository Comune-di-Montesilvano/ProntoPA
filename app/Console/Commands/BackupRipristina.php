<?php

namespace App\Console\Commands;

use App\Services\Backup\CatalogoBackup;
use App\Services\Backup\RipristinoService;
use App\Services\Backup\StatoRipristino;
use Illuminate\Console\Command;
use Throwable;

class BackupRipristina extends Command
{
    protected $signature = 'backup:ripristina
        {file : Nome del backup nel catalogo oppure percorso dello zip}
        {--passphrase= : Passphrase dell\'archivio (default BACKUP_PASSPHRASE)}';

    protected $description = 'Ripristina database e allegati da un backup (sostituisce tutto)';

    public function handle(RipristinoService $ripristino, CatalogoBackup $catalogo): int
    {
        $file = (string) $this->argument('file');
        $zip = $file;
        if (! is_file($zip)) {
            try {
                $zip = $catalogo->percorso($file);
            } catch (Throwable) {
            }
        }
        if (! is_file($zip)) {
            $this->error("Backup non trovato: $file");

            return self::FAILURE;
        }

        $passphrase = (string) ($this->option('passphrase') ?: config('backup.passphrase'));
        if ($passphrase === '') {
            $this->error('Passphrase mancante: usa --passphrase oppure imposta BACKUP_PASSPHRASE.');

            return self::FAILURE;
        }

        try {
            $anteprima = $ripristino->anteprima($zip, $passphrase);
        } catch (Throwable $e) {
            $this->error('Backup non utilizzabile: '.$e->getMessage());

            return self::FAILURE;
        }

        $m = $anteprima->manifest;
        $this->line('Backup:        '.basename($zip));
        $this->line('Creato il:     '.($m['creato_il'] ?? '?').' · versione '.($m['versione'] ?? '?').' · '.($m['app_url'] ?? '?'));
        $this->line('Righe DB:      '.array_sum($m['conteggi']).' in '.count($m['conteggi']).' tabelle');
        $this->line('Allegati:      '.($m['file_storage']['numero'] ?? '?').' file');
        $this->line('Installazione: '.($anteprima->installazioneVuota ? 'vuota (nessun pre-ripristino)' : 'con dati (verrà salvato un pre-ripristino)'));
        if (! $anteprima->stessaAppKey) {
            $this->warn('APP_KEY diversa da quella di origine: i link firmati già inviati non funzioneranno più.');
        }
        if (! $anteprima->stessoAppUrl) {
            $this->warn('APP_URL diverso da quello di origine: ricontrolla le impostazioni legate all\'host.');
        }
        if ($anteprima->bloccante !== null) {
            $this->error($anteprima->bloccante);

            return self::FAILURE;
        }

        if ($this->input->isInteractive()
            && ! $this->confirm('Database e allegati attuali verranno SOSTITUITI. Procedere?', false)) {
            return self::FAILURE;
        }

        $token = StatoRipristino::nuovoToken();
        $ripristino->esegui($zip, $passphrase, $token);
        $stato = StatoRipristino::leggi($token);

        if (($stato['esito'] ?? false) !== true) {
            $this->error((string) ($stato['messaggio'] ?? 'Ripristino non riuscito.'));

            return self::FAILURE;
        }
        $this->info((string) $stato['messaggio']);

        return self::SUCCESS;
    }
}
