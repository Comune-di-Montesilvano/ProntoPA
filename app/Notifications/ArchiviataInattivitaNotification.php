<?php

namespace App\Notifications;

use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Archiviata perché nessuno l'ha aggiornata dopo l'avviso di inattività. */
class ArchiviataInattivitaNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(public readonly Segnalazione $segnalazione) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $id = $this->segnalazione->id_segnalazione;
        $interno = $notifiable instanceof User && ! $notifiable->hasRole('impresa');

        return $this->baseMailMessage("Segnalazione #{$id} archiviata per inattività")
            ->line("La segnalazione #{$id} è stata archiviata: nessun aggiornamento dopo l'avviso di inattività.")
            ->line('Se il problema c\'è ancora, scrivi al comune o apri una nuova segnalazione.')
            ->action($interno ? 'Apri segnalazione' : 'Accedi con SPID o CIE', $interno ? route('segnalazioni.show', $id) : route('accesso'))
            ->salutation('ProntoPA');
    }
}
