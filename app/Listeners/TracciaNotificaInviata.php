<?php

namespace App\Listeners;

use App\Models\NotificaSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Registra ogni notifica partita che riguarda una o più segnalazioni. Scatta all'invio vero
 * (nel worker per le notifiche in coda), una riga per destinatario e canale.
 */
class TracciaNotificaInviata
{
    public function handle(NotificationSent $evento): void
    {
        $ids = self::segnalazioni($evento->notification);
        if ($ids === []) {
            return;
        }

        $notifiable = $evento->notifiable;
        $utente = $notifiable instanceof User ? $notifiable : null;
        $destinatario = $utente?->name
            ?: ($notifiable instanceof AnonymousNotifiable ? (string) ($notifiable->routes[$evento->channel] ?? '') : '');

        foreach ($ids as $id) {
            NotificaSegnalazione::create([
                'id_segnalazione' => $id,
                'tipo'            => class_basename($evento->notification),
                'canale'          => mb_substr((string) $evento->channel, 0, 30),
                'id_utente'       => $utente?->id,
                'destinatario'    => mb_substr($destinatario ?: '—', 0, 191),
            ]);
        }
    }

    /** @return list<int> */
    private static function segnalazioni(object $notifica): array
    {
        if (method_exists($notifica, 'segnalazioniTracciate')) {
            return array_values(array_unique(array_map('intval', $notifica->segnalazioniTracciate())));
        }
        if (property_exists($notifica, 'segnalazione') && $notifica->segnalazione instanceof Segnalazione) {
            return [(int) $notifica->segnalazione->id_segnalazione];
        }
        if (property_exists($notifica, 'idSegnalazione') && $notifica->idSegnalazione) {
            return [(int) $notifica->idSegnalazione];
        }

        return [];
    }
}
