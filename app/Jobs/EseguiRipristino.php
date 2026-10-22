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

    /** Worker ucciso (timeout) a metà: senza questo la UI resterebbe in attesa e l'app in manutenzione. */
    public function failed(?Throwable $e): void
    {
        try {
            if ((StatoRipristino::leggi($this->token)['esito'] ?? null) === null) {
                StatoRipristino::fine($this->token, false,
                    'Ripristino interrotto ('.($e?->getMessage() ?? 'motivo sconosciuto').'): il database può essere incoerente. '
                    .'Ripristina il backup pre-ripristino più recente.');
            }
            Artisan::call('up');
        } catch (Throwable $s) {
            report($s);
        }
    }
}
