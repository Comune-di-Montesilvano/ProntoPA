<?php

namespace App\Services;

use App\Enums\SegnalazioneStato;
use App\Models\Impostazione;
use App\Models\User;
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

    /**
     * La segnalazione aperta per la stessa sede e tipologia su cui l'utente dovrebbe scrivere
     * invece di aprirne un'altra: tra quelle che vede, prima la sua, poi la più recente.
     * Sempre attiva (anche con adesioni spente) e senza limite di giorni. Quelle con un
     * preventivo sono escluse (lavoro già quotato), salvo `$ancheConPreventivo`.
     */
    public function apertaPerSedeETipologia(User $u, int $idPlesso, int $idTipologia, bool $ancheConPreventivo = false): ?Segnalazione
    {
        if ($idPlesso <= 0 || $idTipologia <= 0) {
            return null;
        }

        return Segnalazione::aperte()
            ->visibileA($u)
            ->where('id_plesso', $idPlesso)
            ->where('id_tipologia_segnalazione', $idTipologia)
            ->when(! $ancheConPreventivo, fn ($q) => $q->whereDoesntHave('storicoStati',
                fn ($s) => $s->where('id_stato_segnalazione', SegnalazioneStato::PREVENTIVO_IN_ATTESA->value)))
            ->orderByRaw('CASE WHEN id_utente_segnalazione = ? THEN 0 ELSE 1 END', [$u->id])
            ->orderByDesc('data_segnalazione')
            ->orderByDesc('id_segnalazione')
            ->first();
    }
}
