<?php

namespace App\Notifications;

use App\Models\Azione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperatoreAssegnatoNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly Azione $azione,
        public readonly User $attore,
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
        return $this->baseMailMessage('Nuova segnalazione assegnata #' . $this->segnalazione->id_segnalazione)
            ->line('Ti è stata assegnata la segnalazione #' . $this->segnalazione->id_segnalazione . '.')
            ->line('Stato corrente: ' . ($this->segnalazione->stato?->descrizione ?? 'N/D'))
            ->line('Azione eseguita da: ' . $this->attore->name)
            ->action('Apri segnalazione', route('segnalazioni.show', $this->segnalazione->id_segnalazione))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => implode("\n", [
                'Nuova segnalazione assegnata',
                '#' . $this->segnalazione->id_segnalazione,
                'Stato: ' . ($this->segnalazione->stato?->descrizione ?? 'N/D'),
                'Da: ' . $this->attore->name,
            ]),
        ];
    }
}