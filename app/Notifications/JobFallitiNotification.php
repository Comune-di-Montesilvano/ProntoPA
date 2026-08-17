<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JobFallitiNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    /**
     * @param array<int, array{connection: string, queue: string, exception: string, failed_at: string}> $righe
     */
    public function __construct(
        public readonly int $totale,
        public readonly array $righe,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->baseMailMessage("{$this->totale} job in coda falliti — verifica necessaria")
            ->line("Ci sono {$this->totale} job falliti nella coda (tabella failed_jobs), non ancora rientrati.")
            ->line('Tipicamente: webhook verso il sito del Comune irraggiungibile, o job AI con Ollama non attivo.');

        foreach ($this->righe as $riga) {
            $mail->line("• [{$riga['queue']}] {$riga['failed_at']} — {$riga['exception']}");
        }

        return $mail
            ->line('Verifica ed eventualmente rilancia con `php artisan queue:retry all`, oppure scarta con `php artisan queue:flush` se non più rilevanti.')
            ->line('Questa notifica si ripete a ogni controllo finché la coda non è pulita.');
    }
}
