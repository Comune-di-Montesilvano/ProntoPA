<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AnnualVerificationRequestNotification extends Notification implements ShouldQueue
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
        return $this->baseMailMessage('Conferma annuale account ProntoPA')
            ->line('Per mantenere attivo il tuo account devi confermare annualmente la tua email istituzionale.')
            ->line('Scadenza conferma: '.$this->scadenza)
            ->action('Conferma account', $this->verificationUrl)
            ->line('In assenza di conferma entro la scadenza, l\'account verra sospeso automaticamente.')
            ->salutation('ProntoPA');
    }
}
