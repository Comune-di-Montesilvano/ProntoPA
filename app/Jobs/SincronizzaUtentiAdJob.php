<?php

namespace App\Jobs;

use App\Services\Auth\SincronizzaUtentiAd;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** "Sincronizza ora" da Admin → Utenti: fuori dalla richiesta HTTP, esito salvato dal servizio. */
class SincronizzaUtentiAdJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly bool $forza = false) {}

    public function handle(SincronizzaUtentiAd $sync): void
    {
        $sync->esegui($this->forza);
    }
}
