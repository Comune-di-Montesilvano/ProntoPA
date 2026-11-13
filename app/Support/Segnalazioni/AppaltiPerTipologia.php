<?php

namespace App\Support\Segnalazioni;

use App\Models\Appalto;
use Illuminate\Support\Collection;

/** Pannello Assegna: prima gli appalti che coprono la tipologia (tutte, se più segnalazioni), poi gli altri. */
final class AppaltiPerTipologia
{
    /**
     * @param  Collection<int, Appalto>  $appalti
     * @param  list<int>  $idTipologie
     * @return array{coprono: Collection<int, Appalto>, altri: Collection<int, Appalto>}
     */
    public static function ordina(Collection $appalti, array $idTipologie): array
    {
        $idTipologie = array_values(array_unique(array_filter(array_map('intval', $idTipologie))));
        [$coprono, $altri] = $appalti->partition(
            fn (Appalto $a) => $idTipologie !== [] && collect($idTipologie)->every(fn (int $id) => $a->copreTipologia($id))
        );

        return ['coprono' => $coprono->values(), 'altri' => $altri->values()];
    }
}
