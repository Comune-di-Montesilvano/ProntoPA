<?php

namespace App\Observers;

use App\Models\Plesso;

/**
 * Indirizzo cambiato (form, sync MIUR): la posizione vecchia non vale più, la ritrova il giro
 * delle 06:10. Se nello stesso salvataggio arrivano coordinate a mano, vincono quelle.
 */
class PlessoObserver
{
    public function updating(Plesso $sede): void
    {
        if (! $sede->isDirty('indirizzo') || $sede->isDirty('latitudine') || $sede->isDirty('longitudine')) {
            return;
        }

        $norma = fn ($v) => mb_strtolower(trim((string) $v));
        if ($norma($sede->getOriginal('indirizzo')) === $norma($sede->indirizzo)) {
            return;
        }

        $sede->latitudine = null;
        $sede->longitudine = null;
        $sede->geocodifica_tentata_at = null;
    }
}
