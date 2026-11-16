<?php

namespace App\Observers;

use App\Models\NotaSegnalazione;
use App\Models\User;
use App\Services\Segnalazioni\RichiesteInformazioni;

/** Una nota di chi ha segnalato è la risposta a un'eventuale richiesta di informazioni aperta. */
class NotaSegnalazioneObserver
{
    public function created(NotaSegnalazione $nota): void
    {
        $autore = (int) $nota->id_utente > 0 ? User::find((int) $nota->id_utente) : null;
        $segnalazione = $nota->segnalazione;
        if ($autore && $segnalazione) {
            app(RichiesteInformazioni::class)->registraRisposta($segnalazione, $autore, $nota);
        }
    }
}
