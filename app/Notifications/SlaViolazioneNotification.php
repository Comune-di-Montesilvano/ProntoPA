<?php

namespace App\Notifications;

use App\Channels\TelegramChannel;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SlaViolazioneNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly Segnalazione $segnalazione,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (
            Impostazione::get('telegram_notifica_sla_warning', true) &&
            $notifiable->routeNotificationForTelegram()
        ) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->baseMailMessage('SLA VIOLATO — Segnalazione #' . $this->segnalazione->id_segnalazione)
            ->line('La segnalazione #' . $this->segnalazione->id_segnalazione . ' ha superato il limite SLA.')
            ->line('Priorità: ' . $this->segnalazione->label_priorita)
            ->line('Scadenza era: ' . $this->segnalazione->data_scadenza_sla?->format('d/m/Y H:i'))
            ->action('Apri segnalazione', route('segnalazioni.show', $this->segnalazione->id_segnalazione))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => implode("\n", [
                '🚨 SLA VIOLATO',
                '#' . $this->segnalazione->id_segnalazione,
                'Priorità: ' . $this->segnalazione->label_priorita,
                'Scadenza: ' . $this->segnalazione->data_scadenza_sla?->format('d/m/Y H:i'),
            ]),
        ];
    }
}
