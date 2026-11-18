<?php

namespace App\Services\Segnalazioni;

use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\SollecitoNotification;
use Illuminate\Support\Facades\Notification;

/** Il comune sollecita chi ha il lavoro (operaio, caposquadra o ditta): nota interna + avviso. Scheda e bot Telegram. */
class Sollecito
{
    public function invia(Segnalazione $segnalazione, User $autore, string $testo = ''): void
    {
        $testo = 'Sollecito: '.(trim($testo) ?: 'aggiornare sullo stato del lavoro.');

        $segnalazione->note()->create([
            'testo'            => $testo,
            'id_utente'        => $autore->id,
            'visibile_web'     => false,
            'visibile_impresa' => (bool) $segnalazione->id_appalto,
            'origine'          => NotaSegnalazione::ORIGINE_SOLLECITO,
        ]);

        $destinatari = DestinatariAssegnatario::per($segnalazione, $autore);
        $notifica = new SollecitoNotification($segnalazione, $testo);
        if ($destinatari['utenti']->isNotEmpty()) {
            Notification::send($destinatari['utenti'], $notifica);
        }
        foreach ($destinatari['email'] as $email) {
            Notification::route('mail', $email)->notify($notifica);
        }
    }
}
