<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SetupOtpNotification extends Notification
{
    use BuildsNotificationMailMessage;

    public function __construct(
        public readonly string $otp,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->baseMailMessage('Codice di conferma — setup ProntoPA')
            ->line('Per completare la creazione dell\'account amministratore inserisci questo codice nella pagina di setup:')
            ->line("**{$this->otp}**")
            ->line('Il codice è valido per 10 minuti.')
            ->line('Se non hai richiesto tu questa configurazione, ignora questa email.');
    }
}
