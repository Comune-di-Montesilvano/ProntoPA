<?php

namespace App\Observers;

use App\Models\Segnalazione;
use App\Notifications\NuovaSegnalazioneNotification;
use App\Services\Segnalazioni\DestinatariTipologia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * Avvisa i gestori della tipologia a ogni nuova segnalazione e a ogni cambio di
 * tipologia, qualunque sia il canale (web, API, Telegram, per conto). Sul model
 * e non nei controller perché nessun percorso di creazione resti scoperto.
 */
class SegnalazioneObserver
{
    public function __construct(private readonly DestinatariTipologia $destinatari) {}

    public function created(Segnalazione $s): void
    {
        $this->avvisa($s, false);
    }

    public function updated(Segnalazione $s): void
    {
        if ($s->wasChanged('id_tipologia_segnalazione')) {
            $this->avvisa($s, true);
        }
    }

    private function avvisa(Segnalazione $s, bool $riclassificata): void
    {
        if (Segnalazione::$silenziaAvvisi) {
            return;
        }

        $utente = Auth::user();
        $destinatari = $this->destinatari->perNuova($s, $utente instanceof \App\Models\User ? $utente : null);

        if ($destinatari->isNotEmpty()) {
            Notification::send($destinatari, new NuovaSegnalazioneNotification($s, $riclassificata));
        }
    }
}
