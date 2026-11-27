<?php

namespace App\Listeners;

use App\Channels\TelegramChannel;
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
        $notifica = $evento->notification;
        if (method_exists($notifica, 'tipiTracciati')) {
            // Riepilogo: una riga per (segnalazione, tipo originale), canale col suffisso `:riepilogo`.
            $righe = [];
            foreach ($notifica->tipiTracciati() as $id => $nomi) {
                foreach ($nomi as $nome) {
                    $righe[] = [(int) $id, $nome];
                }
            }
            $suffisso = ':riepilogo';
        } else {
            $righe = array_map(fn (int $id) => [$id, class_basename($notifica)], self::segnalazioni($notifica));
            $suffisso = '';
        }
        if ($righe === []) {
            return;
        }

        $notifiable = $evento->notifiable;
        $utente = $notifiable instanceof User ? $notifiable : null;
        $destinatario = $utente?->name
            ?: ($notifiable instanceof AnonymousNotifiable ? (string) ($notifiable->routes[$evento->channel] ?? '') : '');
        $canale = mb_substr(self::nomeCanale((string) $evento->channel).$suffisso, 0, 30);

        foreach ($righe as [$id, $tipo]) {
            NotificaSegnalazione::create([
                'id_segnalazione' => $id,
                'tipo'            => $tipo,
                'canale'          => $canale,
                'id_utente'       => $utente?->id,
                'destinatario'    => mb_substr($destinatario ?: '—', 0, 191),
            ]);
        }
    }

    /** La classe del canale Telegram diventa `telegram` (come lo mostra la scheda). */
    private static function nomeCanale(string $canale): string
    {
        return $canale === TelegramChannel::class ? 'telegram' : $canale;
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
