<?php

namespace App\Notifications;

use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** Segnalazione ferma: a chi l'ha aperta e a chi l'ha in carico, con la data di archiviazione. */
class InattivitaNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly ?Carbon $archiviazione,
        public readonly Carbon $ultimaAttivita,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $id = $this->segnalazione->id_segnalazione;
        $autore = $notifiable instanceof User && $notifiable->id === $this->segnalazione->id_utente_segnalazione;
        // Ditte (utenti impresa o email aziendale) entrano solo via SPID: niente link diretto alla scheda.
        $interno = $notifiable instanceof User && ! $notifiable->hasRole('impresa');

        $messaggio = $this->baseMailMessage("Segnalazione #{$id} ferma")
            ->line("Sulla segnalazione #{$id} non ci sono aggiornamenti dal {$this->ultimaAttivita->format('d/m/Y')}.");

        $messaggio = $this->archiviazione
            ? $messaggio->line("Se nessuno la aggiorna entro il {$this->archiviazione->format('d/m/Y')} verrà archiviata.")
            : $messaggio->line('Va aggiornata o chiusa.');

        $messaggio = $autore
            ? $messaggio->line('Se il problema c\'è ancora, apri la segnalazione e sollecita: basta per tenerla aperta.')
            : $messaggio->line('Aggiorna la segnalazione con una nota, la fine lavori o un cambio di stato.');

        return $messaggio
            ->action($interno ? 'Apri segnalazione' : 'Accedi con SPID o CIE', $interno ? route('segnalazioni.show', $id) : route('accesso'))
            ->salutation('ProntoPA');
    }
}
