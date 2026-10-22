<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupFallitoNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly string $nomeBackup,
        public readonly string $errore,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->baseMailMessage('Backup fallito')
            ->line("Il backup {$this->nomeBackup} non è andato a buon fine.")
            ->line("Errore: {$this->errore}")
            ->line('I backup precedenti non sono stati toccati. Verifica spazio su disco e log, poi lancia un backup manuale da Admin → Backup.');
    }
}
