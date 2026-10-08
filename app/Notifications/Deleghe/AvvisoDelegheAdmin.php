<?php

namespace App\Notifications\Deleghe;

use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AvvisoDelegheAdmin extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(public readonly string $oggetto, public readonly string $testo) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->baseMailMessage('ProntoPA — Deleghe: '.$this->oggetto)
            ->line($this->testo)
            ->action('Admin → Deleghe', url('/admin/deleghe'))
            ->salutation('ProntoPA');
    }
}
