<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AnnualVerificationReminderNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly string $verificationUrl,
        public readonly string $scadenza,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->baseMailMessage('Promemoria verifica annuale account')
            ->line('Ti ricordiamo di completare la verifica annuale del tuo account.')
            ->line('Scadenza conferma: '.$this->scadenza)
            ->action('Completa verifica', $this->verificationUrl)
            ->salutation('ProntoPA');
    }
}
