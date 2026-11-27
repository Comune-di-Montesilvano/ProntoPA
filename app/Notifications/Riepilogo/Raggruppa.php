<?php

namespace App\Notifications\Riepilogo;

use App\Models\Segnalazione;

/** Marca la notifica generata dal riepilogo stesso: i canali la inviano subito, senza trattenerla. */
trait Raggruppa
{
    public bool $daRiepilogo = false;

    abstract public function segnalazioneRiepilogo(): int;

    /** La segnalazione com'è ora a DB (mai la copia in memoria della notifica); null se eliminata. */
    protected function segnalazioneAttuale(): ?Segnalazione
    {
        return Segnalazione::find($this->segnalazioneRiepilogo());
    }

    /** Regola di base: la segnalazione esiste ancora. */
    protected function segnalazioneEsiste(): bool
    {
        return Segnalazione::whereKey($this->segnalazioneRiepilogo())->exists();
    }

    /** Esiste e non è in uno stato terminale. */
    protected function segnalazioneAperta(): bool
    {
        $s = $this->segnalazioneAttuale();

        return $s !== null && ! $s->statoEnum()->isTerminale();
    }
}
