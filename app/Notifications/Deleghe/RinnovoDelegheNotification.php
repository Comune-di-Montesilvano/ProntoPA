<?php

namespace App\Notifications\Deleghe;

use App\Models\Istituto;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class RinnovoDelegheNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    /** @param list<string> $persone */
    public function __construct(
        public readonly Istituto $istituto,
        public readonly array $persone,
        public readonly string $url,
        public readonly Carbon $scadenza,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->baseMailMessage('ProntoPA — Rinnovo delle deleghe a segnalare guasti — '.$this->istituto->descrizione)
            ->line('Queste persone sono delegate a segnalare guasti per la vostra scuola: indicate chi lavora ancora con voi.')
            ->line(implode(', ', $this->persone))
            ->line('Per confermare o revocare apri questo link: '.$this->url)
            ->line('Le deleghe non confermate scadono. Il link vale fino al '.$this->scadenza->format('d/m/Y').'.')
            ->salutation('ProntoPA');
    }
}
