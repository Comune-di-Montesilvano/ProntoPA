<?php

namespace App\Jobs;

use App\Services\Scuole\AnagrafeMiur;
use App\Services\Scuole\SincronizzaScuole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Scarica l'anagrafe scuole MIUR (~50 MB), la riduce a indice e riallinea
 * istituti/plessi con fonte_dati = miur. In errore l'indice precedente resta
 * in uso e il job finisce in failed_jobs (alert jobs:check-failed).
 */
class ScaricaAnagrafeMiur implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $url) {}

    public function handle(AnagrafeMiur $anagrafe, SincronizzaScuole $sincronizza): void
    {
        // json_decode del file intero: ~10x la dimensione in RAM.
        ini_set('memory_limit', '1G');

        $anagrafe->salvaStato(['stato' => 'in_corso', 'messaggio' => null]);

        try {
            $risposta = Http::timeout(300)->get($this->url);
            if ($risposta->failed()) {
                throw new RuntimeException("download non riuscito (HTTP {$risposta->status()})");
            }

            Storage::disk('local')->put(AnagrafeMiur::DOWNLOAD, $risposta->body());
            unset($risposta);

            $sedi = $anagrafe->indicizza(AnagrafeMiur::DOWNLOAD);

            Storage::disk('local')->delete(AnagrafeMiur::GREZZO);
            Storage::disk('local')->move(AnagrafeMiur::DOWNLOAD, AnagrafeMiur::GREZZO);

            $esito = $sincronizza->riallinea();

            $anagrafe->salvaStato([
                'stato' => 'ok',
                'url' => $this->url,
                'scaricato_at' => now()->toIso8601String(),
                'sedi' => $sedi,
                'messaggio' => "Record aggiornati: {$esito['aggiornati']}"
                    .($esito['da_verificare'] ? '. Sedi passate a istituti non presenti: '.implode(', ', $esito['da_verificare']) : ''),
            ]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete(AnagrafeMiur::DOWNLOAD);
            $anagrafe->salvaStato(['stato' => 'errore', 'messaggio' => $e->getMessage()]);

            throw $e;
        }
    }
}
