<?php

namespace App\Jobs;

use App\Services\Backup\BackupNonConfigurato;
use App\Services\Backup\BackupNonLeggibile;
use App\Services\Backup\BackupService;
use App\Services\Backup\CatalogoBackup;
use App\Services\Backup\StatoRipristino;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use Throwable;

/**
 * Verifica, ricifratura e registrazione di un backup caricato a blocchi: su un
 * file grande durano più dei timeout di PHP/nginx, quindi fuori dalla richiesta.
 * Cifrato: porta la passphrase digitata in UI. Esito su StatoRipristino.
 */
class CompletaCaricamento implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public readonly string $file;

    public readonly string $token;

    private readonly string $passphrase;

    public function __construct(string $file, #[\SensitiveParameter] string $passphrase, string $token)
    {
        $this->file = $file;
        $this->passphrase = $passphrase;
        $this->token = $token;
        $this->onConnection((string) config('backup.connessione_coda'))->onQueue('lunghi');
    }

    public function handle(BackupService $backup, CatalogoBackup $catalogo): void
    {
        try {
            $catalogo->pulisciTemporanei();
            StatoRipristino::fase($this->token, 'verifica', 'Verifica del backup caricato.');
            $info = $backup->registraCaricato($this->file, $this->passphrase);
            StatoRipristino::fine($this->token, true, "Backup caricato e verificato: {$info->nome}.");
        } catch (BackupNonLeggibile|BackupNonConfigurato|InvalidArgumentException $e) {
            StatoRipristino::fine($this->token, false, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            StatoRipristino::fine($this->token, false, 'Caricamento non riuscito: '.$e->getMessage());
        } finally {
            @unlink($this->file);
        }
    }

    /** Worker ucciso o timeout: la pagina non deve restare in attesa per sempre. */
    public function failed(?Throwable $e): void
    {
        @unlink($this->file);

        try {
            if ((StatoRipristino::leggi($this->token)['esito'] ?? null) === null) {
                StatoRipristino::fine($this->token, false,
                    'Caricamento interrotto ('.($e?->getMessage() ?? 'processo terminato').'): riprova.');
            }
        } catch (Throwable $s) {
            report($s);
        }
    }
}
