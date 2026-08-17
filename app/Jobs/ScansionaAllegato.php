<?php

namespace App\Jobs;

use App\Models\AllegatoSegnalazione;
use App\Models\Impostazione;
use App\Services\ClamAvService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ScansionaAllegato implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $idAllegato)
    {
    }

    public function handle(ClamAvService $clamAv): void
    {
        $allegato = AllegatoSegnalazione::find($this->idAllegato);
        if (! $allegato) {
            return; // eliminato prima che la scansione partisse
        }

        if (! $clamAv->isEnabled()) {
            return; // resta 'in_attesa' — nessun verdetto, nessun blocco
        }

        $disk = Impostazione::get('allegati_storage_disk', 'local');
        $path = Storage::disk($disk)->path($allegato->percorso);

        $esito = $clamAv->scanPath($path);

        if ($esito === null) {
            $allegato->update(['stato_scansione' => 'errore']);
            return;
        }

        if ($esito === false) {
            $this->quarantena($allegato, $disk);
            return;
        }

        $allegato->update([
            'stato_scansione' => 'pulito',
            'scansionato_at'  => now(),
        ]);
    }

    /**
     * Sposta (non cancella — serve per eventuali contestazioni) il file su
     * un disco separato, mai esposto dalla route di download.
     */
    private function quarantena(AllegatoSegnalazione $allegato, string $diskOrigine): void
    {
        $sorgente = Storage::disk($diskOrigine);
        $quarantena = Storage::disk('quarantena');

        if ($sorgente->exists($allegato->percorso)) {
            $quarantena->put($allegato->percorso, $sorgente->get($allegato->percorso));
            $sorgente->delete($allegato->percorso);
        }

        $allegato->update([
            'stato_scansione' => 'infetto',
            'scansionato_at'  => now(),
        ]);

        Log::warning('Allegato infetto messo in quarantena', [
            'id_allegato'     => $allegato->id_allegato,
            'id_segnalazione' => $allegato->id_segnalazione,
            'nome_originale'  => $allegato->nome_originale,
        ]);
    }
}
