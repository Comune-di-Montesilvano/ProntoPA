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
     * Trova segnalazioni aperte simili: stessa tipologia E
     * (stesso plesso O entro il raggio configurato) E recenti.
     * Collection vuota se la funzione è disattivata o senza luogo.
     */
    public function trovaSimili(
        int    $idTipologia,
        ?int   $idPlesso = null,
        ?float $lat = null,
        ?float $lng = null,
        ?string $testo = null,
        int    $limite = 5,
    ): Collection {
        if (! Impostazione::get('adesioni_enabled', false)) {
            return collect();
        }

        $haPlesso = $idPlesso !== null && $idPlesso > 0;
        $haCoordinate = $lat !== null && $lng !== null && ($lat != 0.0 || $lng != 0.0);

        if (! $haPlesso && ! $haCoordinate) {
            return collect();
        }

        $giorni = (int) Impostazione::get('dedup_giorni', 90);
        $raggio = (int) Impostazione::get('dedup_raggio_metri', 150);

        $query = Segnalazione::aperte()
            ->where('id_tipologia_segnalazione', $idTipologia)
            ->where('data_segnalazione', '>=', now()->subDays($giorni));

        $query->where(function ($q) use ($haPlesso, $haCoordinate, $idPlesso, $lat, $lng, $raggio) {
            if ($haPlesso) {
                $q->orWhere('id_plesso', $idPlesso);
            }
            if ($haCoordinate) {
                // Bounding box approssimato: 1° lat ≈ 111.32 km
                $dLat = $raggio / 111320;
                $dLng = $raggio / (111320 * max(cos(deg2rad($lat)), 0.01));
                $q->orWhere(function ($qq) use ($lat, $lng, $dLat, $dLng) {
                    $qq->whereBetween('latitudine', [$lat - $dLat, $lat + $dLat])
                       ->whereBetween('longitudine', [$lng - $dLng, $lng + $dLng])
                       ->where(fn ($z) => $z->where('latitudine', '!=', 0)->orWhere('longitudine', '!=', 0));
                });
            }
        });

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
