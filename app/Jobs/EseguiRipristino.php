<?php

namespace App\Jobs;

use App\Services\Backup\RipristinoService;
use App\Services\Backup\StatoRipristino;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Throwable;

/** Cifrato: la passphrase digitata in UI non deve restare in chiaro in Redis. */
class EseguiRipristino implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public readonly string $zip;

    public readonly string $token;

    private readonly string $passphrase;

    public function __construct(string $zip, #[\SensitiveParameter] string $passphrase, string $token)
    {
        $this->zip = $zip;
        $this->passphrase = $passphrase;
        $this->token = $token;
        $this->onConnection((string) config('backup.connessione_coda'))->onQueue('lunghi');
    }

    public function handle(RipristinoService $ripristino): void
    {
        $ripristino->esegui($this->zip, $this->passphrase, $this->token);
    }

    /**
     * Worker ucciso a metà (OOM, timeout): il job viene ripreso dopo retry_after
     * e, finiti i tentativi, arriva qui. Niente rollback automatico da qui:
     * si riporta su l'app e si indica quale backup ripristinare.
     */
    public function failed(?Throwable $e): void
    {
        try {
            Artisan::call('up');
        } catch (Throwable $s) {
            report($s);
        }

        try {
            $stato = StatoRipristino::leggi($this->token);
            if (($stato['esito'] ?? null) === null) {
                $cosa = isset($stato['pre_ripristino'])
                    ? 'Ripristina il backup '.$stato['pre_ripristino'].'.'
                    : "Usa l'ultimo backup pre-ripristino in elenco.";
                StatoRipristino::fine($this->token, false,
                    'Ripristino interrotto a metà ('.($e?->getMessage() ?? 'processo terminato').'): '
                    ."il database può essere incoerente. $cosa");
            }
        } catch (Throwable $s) {
            report($s);
        }

        if (preg_match('/^[A-Za-z0-9]{40}$/', $this->token)) {
            $tmp = rtrim((string) config('backup.path'), '/\\').'/tmp/ripristino-'.$this->token;
            File::deleteDirectory($tmp);
            File::deleteDirectory($tmp.'-rollback');
        }

        if ($e !== null) {
            report($e);
        }
    }
}
