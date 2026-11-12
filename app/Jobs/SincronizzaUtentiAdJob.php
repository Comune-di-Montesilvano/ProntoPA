<?php

namespace App\Jobs;

use App\Services\Auth\SincronizzaUtentiAd;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** "Sincronizza ora" da Admin → Utenti: fuori dalla richiesta HTTP, esito salvato dal servizio. */
class SincronizzaUtentiAdJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Segnale "giro richiesto e non ancora finito": evita doppioni dal pulsante. */
    public const CHIAVE_IN_CODA = 'ldap-sync-in-coda';

    public int $tries = 1;

    public int $timeout = 600;

    /** @param  int|null  $confermati  disattivazioni confermate dall'admin con "Sincronizza comunque" */
    public function __construct(public readonly bool $forza = false, public readonly ?int $confermati = null) {}

    public function handle(SincronizzaUtentiAd $sync): void
    {
        try {
            $sync->esegui($this->forza, confermati: $this->confermati);
        } finally {
            Cache::forget(self::CHIAVE_IN_CODA);
        }
    }

    public function failed(?\Throwable $e): void
    {
        Cache::forget(self::CHIAVE_IN_CODA);
    }
}
