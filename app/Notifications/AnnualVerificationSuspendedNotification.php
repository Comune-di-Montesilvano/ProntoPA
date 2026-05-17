<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AnnualVerificationSuspendedNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->baseMailMessage('Account sospeso per mancata verifica annuale')
            ->line('Il tuo account e stato sospeso per mancata conferma annuale della email istituzionale.')
            ->line('Contatta un operatore per la riattivazione.')
            ->salutation('ProntoPA');
    }
}
