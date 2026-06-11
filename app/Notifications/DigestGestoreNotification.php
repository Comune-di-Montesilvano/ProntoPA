<?php

namespace App\Notifications;

use App\Channels\TelegramChannel;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class DigestGestoreNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly Collection $inRitardo,
        public readonly Collection $inScadenza,
        public readonly Collection $nonAssegnate,
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
        $mail = $this->baseMailMessage('Digest segnalazioni — ' . now()->format('d/m/Y'));

        if ($this->inRitardo->isNotEmpty()) {
            $mail->line('🔴 In ritardo (' . $this->inRitardo->count() . '):');
            foreach ($this->inRitardo as $s) {
                $mail->line('#' . $s->id_segnalazione . ' — ' . \Illuminate\Support\Str::limit($s->testo_segnalazione, 60)
                    . ' (scaduta il ' . $s->data_scadenza_sla?->format('d/m') . ')');
            }
        }

        if ($this->inScadenza->isNotEmpty()) {
            $mail->line('🟡 In scadenza (' . $this->inScadenza->count() . '):');
            foreach ($this->inScadenza as $s) {
                $mail->line('#' . $s->id_segnalazione . ' — ' . \Illuminate\Support\Str::limit($s->testo_segnalazione, 60)
                    . ' (scade il ' . $s->data_scadenza_sla?->format('d/m H:i') . ')');
            }
        }

        if ($this->nonAssegnate->isNotEmpty()) {
            $mail->line('⚪ Nuove non assegnate (' . $this->nonAssegnate->count() . '):');
            foreach ($this->nonAssegnate as $s) {
                $mail->line('#' . $s->id_segnalazione . ' — ' . \Illuminate\Support\Str::limit($s->testo_segnalazione, 60));
            }
        }

        return $mail
            ->action('Apri la dashboard', route('gestione.dashboard'))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        $righe = ['📋 Digest ' . now()->format('d/m/Y')];

        foreach ([
            '🔴 In ritardo'   => $this->inRitardo,
            '🟡 In scadenza'  => $this->inScadenza,
            '⚪ Non assegnate' => $this->nonAssegnate,
        ] as $label => $gruppo) {
            if ($gruppo->isNotEmpty()) {
                $righe[] = $label . ': ' . $gruppo->pluck('id_segnalazione')->map(fn ($id) => '#' . $id)->implode(' ');
            }
        }

        return ['text' => implode("\n", $righe)];
    }
}
