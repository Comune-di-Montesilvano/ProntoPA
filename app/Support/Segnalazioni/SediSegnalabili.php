<?php

namespace App\Support\Segnalazioni;

use App\Models\Delega;
use App\Models\Plesso;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sedi su cui una persona può aprire una segnalazione (1.0.0: la sede è obbligatoria).
 * Scuola SPID: le sedi coperte dalle sue deleghe; personale del comune: tutte.
 * Sempre e solo sedi di enti attivi. La visibilità delle segnalazioni già aperte
 * resta quella delle deleghe (`Delega::plessiCopertiDa`), anche su enti disattivati.
 */
class SediSegnalabili
{
    /** @return Collection<int, Plesso> */
    public static function per(User $user): Collection
    {
        $query = Plesso::with('istituto.categoria')
            ->whereHas('istituto', fn ($q) => $q->where('attivo', true));

        if ($user->isSpid()) {
            $query->whereIn('id_plesso', Delega::plessiCopertiDa($user));
        }

        // Per categoria (ordine configurato), poi ente, poi sede: la select le raggruppa così.
        return $query->get()->sortBy([
            ['istituto.categoria.ordine', 'asc'],
            ['istituto.categoria.nome', 'asc'],
            ['istituto.descrizione', 'asc'],
            ['nome', 'asc'],
        ])->values();
    }
}
