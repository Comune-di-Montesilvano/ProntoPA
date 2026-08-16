<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BenvenutoAdminNotification extends Notification
{
    use BuildsNotificationMailMessage;

    public function __construct(
        public readonly string $resetUrl,
        public readonly string $enteName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->baseMailMessage("Benvenuto in {$this->enteName} — imposta la tua password")
            ->line("Il tuo account amministratore è stato creato per {$this->enteName}.")
            ->line('Per accedere devi impostare una password cliccando sul link qui sotto. Il link è valido per 30 giorni.')
            ->action('Imposta password', $this->resetUrl)
            ->line('Se non hai richiesto questo account, ignora questa email.')
            ->salutation($this->enteName);
    }
}
