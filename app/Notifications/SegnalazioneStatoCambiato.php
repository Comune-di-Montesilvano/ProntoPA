<?php

namespace App\Notifications;

use App\Models\Azione;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SegnalazioneStatoCambiato extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly Azione       $azione,
        public readonly User         $attore,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('segnalazioni.show', $this->segnalazione->id_segnalazione);

        return (new MailMessage)
            ->subject('Aggiornamento segnalazione #' . $this->segnalazione->id_segnalazione)
            ->greeting('Buongiorno,')
            ->line('La segnalazione **#' . $this->segnalazione->id_segnalazione . '** ha ricevuto un aggiornamento.')
            ->line('**Azione eseguita:** ' . $this->azione->descrizione)
            ->line('**Nuovo stato:** ' . $this->segnalazione->stato->descrizione)
            ->action('Visualizza segnalazione', $url)
            ->salutation('ProntoPA');
    }
}
