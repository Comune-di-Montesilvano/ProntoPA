<?php

namespace App\Jobs;

use App\Models\Segnalazione;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalcolaEmbeddingSegnalazione implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $idSegnalazione) {}

    public function handle(OllamaService $ollama): void
    {
        $segnalazione = Segnalazione::find($this->idSegnalazione);
        if (! $segnalazione || filled($segnalazione->embedding)) {
            return;
        }

        $testo = $segnalazione->testo_segnalazione;
        if (blank($testo)) {
            return;
        }

        $embedding = $ollama->embed($testo);
        if ($embedding === null) {
            return;
        }

        $segnalazione->update(['embedding' => $embedding]);
    }
}
