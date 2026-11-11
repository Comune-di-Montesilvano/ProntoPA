<?php

namespace App\Services\Segnalazioni;

use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A chi mandare gli avvisi di una segnalazione in base alla sua tipologia
 * (spec ruoli e smistamento, 0.9.13). La copertura la decide CoperturaTipologie:
 * qui solo la regola per evento. Mai account disattivati né senza email
 * (già esclusi dalla copertura).
 */
class DestinatariTipologia
{
    public function __construct(private readonly CoperturaTipologie $copertura) {}

    /**
     * Nuova o riclassificata: gestori della tipologia; se orfana i supervisori.
     *
     * @return Collection<int, User>
     */
    public function perNuova(Segnalazione $s, ?User $escludi = null): Collection
    {
        return $this->gestoriOSupervisori($s)
            ->reject(fn (User $u) => $escludi !== null && $u->id === $escludi->id)
            ->values();
    }

    /**
     * SLA scaduto: gestori della tipologia e sempre i supervisori.
     *
     * @return Collection<int, User>
     */
    public function perSlaScaduto(Segnalazione $s): Collection
    {
        return $this->gestori($s)->concat($this->copertura->supervisori())->unique('id')->values();
    }

    /**
     * SLA a rischio: gestori della tipologia; se orfana i supervisori.
     *
     * @return Collection<int, User>
     */
    public function perSlaArischio(Segnalazione $s): Collection
    {
        return $this->gestoriOSupervisori($s);
    }

    /** @return Collection<int, User> */
    private function gestori(Segnalazione $s): Collection
    {
        $id = (int) $s->id_tipologia_segnalazione;

        return $id > 0 ? collect($this->copertura->gestoriDi($id)->all()) : collect();
    }

    /** @return Collection<int, User> */
    private function gestoriOSupervisori(Segnalazione $s): Collection
    {
        $gestori = $this->gestori($s);

        return ($gestori->isNotEmpty() ? $gestori : collect($this->copertura->supervisori()->all()))
            ->unique('id')->values();
    }
}
