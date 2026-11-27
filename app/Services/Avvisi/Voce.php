<?php

namespace App\Services\Avvisi;

use App\Models\Segnalazione;
use App\Notifications\Riepilogo\Raggruppabile;

/**
 * Una riga del riepilogo: una segnalazione, nella sezione della categoria più importante
 * (o nella sezione del quadro del mattino), con tutti i motivi per cui compare.
 */
final class Voce
{
    /**
     * @param  string  $categoria  chiave di `Categorie` oppure, nel quadro, `nuove`|`da_fare`|`in_ritardo`
     * @param  list<string>  $motivi
     * @param  Raggruppabile|null  $notifica  la notifica della categoria vincente; null nel quadro
     */
    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly string $categoria,
        public readonly array $motivi,
        public readonly string $riga,
        public readonly ?Raggruppabile $notifica,
    ) {}
}
