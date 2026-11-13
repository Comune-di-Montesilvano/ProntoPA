<?php

namespace App\Services;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Services\OllamaService;
use App\Support\CosineSimilarity;
use Illuminate\Support\Collection;

class DedupService
{
    /**
     * Trova segnalazioni aperte simili: stessa tipologia E stessa sede E recenti
     * (+ somiglianza del testo se l'AI è attiva). Vuota se spenta o senza sede.
     */
    public function trovaSimili(
        int    $idTipologia,
        ?int   $idPlesso = null,
        ?string $testo = null,
        int    $limite = 5,
        bool   $soloSeAttivo = true,
    ): Collection {
        // L'accorpamento dalla scheda funziona anche con le adesioni pubbliche spente.
        if ($soloSeAttivo && ! Impostazione::get('adesioni_enabled', false)) {
            return collect();
        }

        if ($idPlesso === null || $idPlesso <= 0) {
            return collect();
        }

        $giorni = (int) Impostazione::get('dedup_giorni', 90);

        $query = Segnalazione::aperte()
            ->where('id_tipologia_segnalazione', $idTipologia)
            ->where('id_plesso', $idPlesso)
            ->where('data_segnalazione', '>=', now()->subDays($giorni));

        $candidati = $query->with(['stato', 'tipologia', 'allegati', 'adesioni'])
            ->orderByDesc('data_segnalazione')
            ->limit($limite * 3)
            ->get();

        $ollama = app(OllamaService::class);
        if (filled($testo) && $ollama->isEnabled()) {
            $embeddingQuery = $ollama->embed($testo);
            if ($embeddingQuery !== null) {
                $candidati = $candidati
                    ->filter(function (Segnalazione $s) use ($embeddingQuery): bool {
                        if (! is_array($s->embedding) || empty($s->embedding)) {
                            return true;
                        }
                        return CosineSimilarity::compute($embeddingQuery, $s->embedding) >= 0.80;
                    })
                    ->values();
            }
        }

        return $candidati->take($limite);
    }
}
