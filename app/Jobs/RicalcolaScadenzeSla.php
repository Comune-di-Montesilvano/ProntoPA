<?php

namespace App\Jobs;

use App\Models\Segnalazione;
use App\Services\SlaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Ricalcola la scadenza SLA delle segnalazioni aperte dopo una modifica di regole,
 * orario d'ufficio o patrono. Se la nuova scadenza è nel futuro il "superato" si
 * azzera, se non è più a rischio l'avviso può ripartire.
 */
class RicalcolaScadenzeSla implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(SlaService $sla): void
    {
        Segnalazione::aperte()->chunkById(200, function ($segnalazioni) use ($sla) {
            foreach ($segnalazioni as $s) {
                $scadenza = $sla->calcolaScadenza($s);
                if (! $scadenza) {
                    continue; // nessuna regola applicabile: scadenza lasciata com'è
                }

                $s->data_scadenza_sla = $scadenza;
                if ($scadenza->isFuture()) {
                    $s->sla_violato = false;
                    if (! $sla->isArischio($s)) {
                        $s->sla_warning_inviato = false;
                    }
                }

                // Niente eventi del modello: non è un cambio della segnalazione da notificare.
                if ($s->isDirty()) {
                    $s->saveQuietly();
                }
            }
        }, 'id_segnalazione');
    }
}
