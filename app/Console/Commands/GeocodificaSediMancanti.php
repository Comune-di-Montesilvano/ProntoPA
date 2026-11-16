<?php

namespace App\Console\Commands;

use App\Jobs\GeocodificaSedi;
use App\Models\Plesso;
use Illuminate\Console\Command;

/** Ogni mattina: posizione per le sedi nuove o con indirizzo cambiato (job a 1 richiesta/s). */
class GeocodificaSediMancanti extends Command
{
    protected $signature = 'sedi:geocodifica';

    protected $description = 'Cerca la posizione delle sedi con indirizzo, senza coordinate e mai provate';

    public function handle(): void
    {
        $quante = Plesso::daGeocodificare()->count();
        if ($quante === 0) {
            $this->info('Sedi da geocodificare: nessuna.');

            return;
        }

        GeocodificaSedi::dispatch();
        $this->info("Sedi da geocodificare: {$quante}, accodate.");
    }
}
