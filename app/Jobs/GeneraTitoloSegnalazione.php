<?php

namespace App\Jobs;

use App\Models\Segnalazione;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class GeneraTitoloSegnalazione implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $idSegnalazione) {}

    public function handle(OllamaService $ollama): void
    {
        $segnalazione = Segnalazione::find($this->idSegnalazione);
        if (! $segnalazione) {
            return;
        }

        $testo = $segnalazione->testo_segnalazione;
        if (blank($testo)) {
            return;
        }

        $prompt = <<<PROMPT
Sei un assistente per la PA italiana. Genera un titolo brevissimo (massimo 8 parole) per questa segnalazione di manutenzione. Rispondi SOLO con il titolo, senza punteggiatura finale, senza virgolette.

Segnalazione: {$testo}

Titolo:
PROMPT;

        $titolo = $ollama->generate($prompt);
        if (blank($titolo)) {
            return;
        }

        $titolo = Str::limit(trim($titolo, " \n\"'"), 200, '');

        $segnalazione->update(['titolo_generato' => $titolo]);
    }
}
