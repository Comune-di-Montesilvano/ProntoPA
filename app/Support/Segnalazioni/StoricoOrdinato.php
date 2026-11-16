<?php

namespace App\Support\Segnalazioni;

use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use Illuminate\Support\Collection;

/**
 * Storico stati dal più vecchio, senza le righe che ripetono la precedente.
 *
 * La relazione `storicoStati()` è ordinata al contrario: qui si riordina (data, poi id).
 * Una riga senza operatore né appalto (es. "Riprendi") lascia l'assegnatario com'era;
 * righe consecutive con stesso stato e stesso assegnatario sono un solo passaggio
 * (doppioni a DB di "Assegna a me" a vuoto, prima di 1.0.2).
 */
class StoricoOrdinato
{
    /** @return Collection<int, StoricoStatoSegnalazione> */
    public static function di(Segnalazione $s): Collection
    {
        $righe = $s->storicoStati()->reorder('data_registrazione')->orderBy('id')->get();

        $tenute = collect();
        $chiave = null;
        $assegnatario = '0:0';
        foreach ($righe as $riga) {
            $collegato = (int) $riga->id_utente_collegato;
            $appalto = (int) $riga->id_appalto;
            if ($collegato > 0 || $appalto > 0) {
                $assegnatario = $collegato.':'.$appalto;
            }
            $questa = (int) $riga->id_stato_segnalazione.'|'.$assegnatario;
            if ($questa !== $chiave) {
                $tenute->push($riga);
            }
            $chiave = $questa;
        }

        return $tenute->values();
    }
}
