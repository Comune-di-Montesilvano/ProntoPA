<?php

namespace App\Jobs;

use App\Models\Segnalazione;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SuggerisciTriageSegnalazione implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $idSegnalazione) {}

    public function handle(OllamaService $ollama): void
    {
        $segnalazione = Segnalazione::with(['tipologia', 'specializzazione'])->find($this->idSegnalazione);
        if (! $segnalazione || filled($segnalazione->triage_suggerito)) {
            return;
        }

        $testo = $segnalazione->testo_segnalazione;
        if (blank($testo)) {
            return;
        }

        $prompt = <<<PROMPT
Sei un assistente tecnico per la PA italiana. Analizza questa segnalazione di manutenzione e suggerisci il triage.
Rispondi SOLO con JSON valido, senza spiegazioni, nel formato:
{"id_tipologia_segnalazione": <intero 1-3>, "id_specializzazione": <intero o null>, "livello_priorita": <intero 1-4>}

Livelli priorità: 1=Bassa, 2=Normale, 3=Alta, 4=Critica
Tipologie: 1=Impianti, 2=Edile, 3=Altro

Segnalazione: {$testo}

JSON:
PROMPT;

        $risposta = $ollama->generate($prompt);
        if (blank($risposta)) {
            return;
        }

        if (preg_match('/\{[^}]+\}/', $risposta, $matches) !== 1) {
            return;
        }

        $triage = json_decode($matches[0], true);
        if (! is_array($triage)) {
            return;
        }

        $idTipologia = filter_var($triage['id_tipologia_segnalazione'] ?? null, FILTER_VALIDATE_INT);
        $priorita    = filter_var($triage['livello_priorita'] ?? null, FILTER_VALIDATE_INT);

        if ($idTipologia === false || $priorita === false) {
            return;
        }

        $segnalazione->update([
            'triage_suggerito' => [
                'id_tipologia_segnalazione' => (int) $idTipologia,
                'id_specializzazione'       => isset($triage['id_specializzazione'])
                    ? (int) $triage['id_specializzazione']
                    : null,
                'livello_priorita'          => max(1, min(4, (int) $priorita)),
            ],
        ]);
    }
}
