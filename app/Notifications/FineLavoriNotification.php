<?php

namespace App\Notifications;

use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Avvisa il comune che ditta o operaio hanno finito: resta da verificare e chiudere. */
class FineLavoriNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly NotaSegnalazione $nota,
        public readonly string $mittente,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $id = $this->segnalazione->id_segnalazione;

        return $this->baseMailMessage("Fine lavori comunicata su #{$id}")
            ->line("{$this->mittente} ha comunicato la fine dei lavori sulla segnalazione #{$id}.")
            ->line(Str::limit($this->nota->testo, 500))
            ->line('Verifica le foto e chiudi la segnalazione.')
            ->action('Apri segnalazione', route('segnalazioni.show', $id))
            ->salutation('ProntoPA');
    }
}
