<?php

namespace App\Notifications;

use App\Channels\TelegramChannel;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class DigestGestoreNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;

    public function __construct(
        public readonly array $inRitardoIds,
        public readonly array $inScadenzaIds,
        public readonly array $nonAssegnateIds,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if ($notifiable->routeNotificationForTelegram()) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inRitardo    = $this->carica($this->inRitardoIds);
        $inScadenza   = $this->carica($this->inScadenzaIds);
        $nonAssegnate = $this->carica($this->nonAssegnateIds);

        $mail = $this->baseMailMessage('Digest segnalazioni — ' . now()->format('d/m/Y'));

        if ($inRitardo->isNotEmpty()) {
            $mail->line('🔴 In ritardo (' . $inRitardo->count() . '):');
            foreach ($inRitardo as $s) {
                $mail->line($this->rigaSegnalazione($s) . ' (scaduta il ' . Carbon::parse($s->data_scadenza_sla)->format('d/m') . ')');
            }
        }

        if ($inScadenza->isNotEmpty()) {
            $mail->line('🟡 In scadenza (' . $inScadenza->count() . '):');
            foreach ($inScadenza as $s) {
                $mail->line($this->rigaSegnalazione($s) . ' (scade il ' . Carbon::parse($s->data_scadenza_sla)->format('d/m H:i') . ')');
            }
        }

        if ($nonAssegnate->isNotEmpty()) {
            $mail->line('⚪ Nuove non assegnate (' . $nonAssegnate->count() . '):');
            foreach ($nonAssegnate as $s) {
                $mail->line($this->rigaSegnalazione($s));
            }
        }

        return $mail
            ->action('Apri la dashboard', route('gestione.dashboard'))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        $inRitardo    = $this->carica($this->inRitardoIds);
        $inScadenza   = $this->carica($this->inScadenzaIds);
        $nonAssegnate = $this->carica($this->nonAssegnateIds);

        $righe = ['📋 Digest ' . now()->format('d/m/Y')];

        foreach ([
            '🔴 In ritardo'   => $inRitardo,
            '🟡 In scadenza'  => $inScadenza,
            '⚪ Non assegnate' => $nonAssegnate,
        ] as $label => $gruppo) {
            if ($gruppo->isNotEmpty()) {
                $righe[] = $label . ': ' . $gruppo->pluck('id_segnalazione')->map(fn ($id) => '#' . $id)->implode(' ');
            }
        }

        return ['text' => implode("\n", $righe)];
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\Segnalazione> */
    private function carica(array $ids): \Illuminate\Support\Collection
    {
        if (empty($ids)) {
            return collect();
        }

        // Ordine dei parametri (già ordinati da chi chiama), senza FIELD(): solo MariaDB.
        $posizione = array_flip(array_map('intval', $ids));

        return \App\Models\Segnalazione::with('tipologia')->whereIn('id_segnalazione', $ids)->get()
            ->sortBy(fn ($s) => $posizione[$s->id_segnalazione] ?? PHP_INT_MAX)
            ->values();
    }
}
