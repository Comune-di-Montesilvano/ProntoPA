<?php

namespace App\Jobs;

use App\Models\Impostazione;
use App\Models\Plesso;
use App\Services\Sedi\Geocoding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Sleep;

/** "Trova le posizioni mancanti": una richiesta al secondo, esito per Stato del sistema. */
class GeocodificaSedi implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CHIAVE_ESITO = 'geocoding_esito';

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    /** Coda dei lavori lunghi (retry_after ~2 h): sulla coda normale (90 s) verrebbe riconsegnato a metà. */
    public function __construct()
    {
        $this->onConnection((string) config('backup.connessione_coda'))->onQueue('lunghi');
    }

    public function handle(Geocoding $geocoding): void
    {
        $conteggi = ['trovate' => 0, 'non_trovate' => 0, 'errori' => 0];
        $prima = true;

        Plesso::daGeocodificare()->orderBy('id_plesso')->get()
            ->each(function (Plesso $sede) use ($geocoding, &$conteggi, &$prima) {
                if (! $prima) {
                    Sleep::for(1)->second();
                }
                $prima = false;

                $esito = $geocoding->esegui((string) $sede->indirizzo);
                match ($esito['stato']) {
                    Geocoding::TROVATA => $conteggi['trovate']++,
                    Geocoding::NON_TROVATA => $conteggi['non_trovate']++,
                    default => $conteggi['errori']++,
                };
                // Errore di rete: nessun segno, si riprova al giro di domani.
                if ($esito['stato'] === Geocoding::TROVATA) {
                    $sede->update(['latitudine' => $esito['lat'], 'longitudine' => $esito['lng'], 'geocodifica_tentata_at' => now()]);
                } elseif ($esito['stato'] === Geocoding::NON_TROVATA) {
                    $sede->update(['geocodifica_tentata_at' => now()]);
                }
            });

        Impostazione::set(self::CHIAVE_ESITO, json_encode(['quando' => now()->toIso8601String()] + $conteggi));
    }
}
