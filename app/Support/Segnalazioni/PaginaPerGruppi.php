<?php

namespace App\Support\Segnalazioni;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Paginatore dell'elenco raggruppato (1.0.4): una pagina = uno o più gruppi di primo livello interi,
 * quindi le pagine hanno righe in numero variabile. I numeri di pagina vengono dai gruppi,
 * "da … a … di …" dalle righe vere.
 *
 * @extends LengthAwarePaginator<int, \App\Models\Segnalazione>
 */
class PaginaPerGruppi extends LengthAwarePaginator
{
    private int $primaRiga;

    private int $righeTotali;

    /**
     * @param  Collection<int, \App\Models\Segnalazione>  $righe
     * @param  array<string, mixed>  $opzioni
     */
    public function __construct(Collection $righe, int $pagine, int $pagina, int $primaRiga, int $righeTotali, array $opzioni = [])
    {
        parent::__construct($righe, $pagine, 1, $pagina, $opzioni);
        $this->primaRiga = $primaRiga;
        $this->righeTotali = $righeTotali;
    }

    public function total(): int
    {
        return $this->righeTotali;
    }

    public function firstItem(): ?int
    {
        return $this->items->isEmpty() ? null : $this->primaRiga;
    }

    public function lastItem(): ?int
    {
        return $this->items->isEmpty() ? null : $this->primaRiga + $this->items->count() - 1;
    }
}
