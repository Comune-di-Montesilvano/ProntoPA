<?php

namespace App\Console\Commands;

use App\Services\Segnalazioni\RichiesteInformazioni;
use Illuminate\Console\Command;

class ControllaRichiesteInformazioni extends Command
{
    protected $signature   = 'segnalazioni:richieste';
    protected $description = 'Promemoria a chi non ha ancora risposto alle richieste di informazioni e chiusura di quelle scadute';

    public function handle(): void
    {
        $esito = app(RichiesteInformazioni::class)->controlla();

        $this->info("Richieste di informazioni: {$esito['promemoria']} promemoria, {$esito['chiuse']} chiuse, {$esito['tornate']} tornate al comune.");
    }
}
