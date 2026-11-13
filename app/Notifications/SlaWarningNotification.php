<?php

namespace App\Notifications;

use App\Channels\TelegramChannel;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SlaWarningNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
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
        $mail = $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'In scadenza'))
            ->line('La segnalazione #' . $this->segnalazione->id_segnalazione . ' sta per scadere.')
            ->line('Priorità: ' . $this->segnalazione->label_priorita);

        return $this->riepilogoSegnalazione($mail, $this->segnalazione, perChiLavora: true)
            ->action('Apri segnalazione', route('segnalazioni.show', $this->segnalazione->id_segnalazione))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => implode("\n", [
                '⚠️ In scadenza',
                $this->rigaSegnalazione($this->segnalazione),
                'Priorità: ' . $this->segnalazione->label_priorita,
                'Scade: ' . $this->segnalazione->data_scadenza_sla?->format('d/m/Y H:i'),
            ]),
        ];
    }
}
