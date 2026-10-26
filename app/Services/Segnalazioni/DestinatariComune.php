<?php

namespace App\Services\Segnalazioni;

use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Chi nel comune deve sapere che ditta, operaio o segnalante hanno scritto:
 * il gestore assegnatario se c'è, altrimenti i supervisori attivi.
 */
class DestinatariComune
{
    /** @return Collection<int, User> */
    public static function per(Segnalazione $segnalazione, ?User $escludi = null): Collection
    {
        $assegnatario = $segnalazione->id_operatore_assegnato
            ? User::find($segnalazione->id_operatore_assegnato)
            : null;

        if ($assegnatario && $assegnatario->attivo !== false && $assegnatario->hasAnyRole(['gestore', 'admin'])) {
            $destinatari = collect([$assegnatario]);
        } else {
            $destinatari = User::role('gestore')
                ->where('supervisore_segnalazioni', true)
                ->get()
                ->filter(fn (User $u) => $u->attivo !== false);
        }

        return $destinatari->reject(fn (User $u) => $escludi && $u->id === $escludi->id)->values();
    }
}
