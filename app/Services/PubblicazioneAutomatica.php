<?php

namespace App\Services;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use Illuminate\Support\Facades\Cache;

/**
 * Regola di pubblicazione automatica (Admin → Impostazioni → Pubblicazione)
 * applicata alle segnalazioni già esistenti: il workflow la applica solo ai
 * cambi di stato, quindi import e segnalazioni precedenti restano fuori.
 */
class PubblicazioneAutomatica
{
    public function statoTrigger(): ?int
    {
        if (! Impostazione::get('publication_enabled', false)) {
            return null;
        }

        $stato = Impostazione::get('publication_auto_state_id');

        return blank($stato) ? null : (int) $stato;
    }

    /** Numero di segnalazioni pubblicate; null se la regola non è attiva. */
    public function applicaAEsistenti(?int $giorni = null): ?int
    {
        $stato = $this->statoTrigger();
        if ($stato === null) {
            return null;
        }

        $pubblicate = Segnalazione::query()
            ->where('flag_pubblicata', false)
            ->where('id_stato_segnalazione', '>=', $stato)
            ->when($giorni, fn ($q) => $q->where('data_segnalazione', '>=', now()->subDays($giorni)->startOfDay()))
            ->update(['flag_pubblicata' => true, 'flag_riservata' => false]);

        Cache::forget('public.home.statistics');

        return $pubblicate;
    }
}
