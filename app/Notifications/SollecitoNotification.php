<?php

namespace App\Notifications;

use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Il comune chiede a chi ha il lavoro (ditta, operaio, caposquadra) un aggiornamento. */
class SollecitoNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly string $testo,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $id = $this->segnalazione->id_segnalazione;
        // Le ditte entrano solo via SPID: niente link diretto alla scheda.
        $interno = $notifiable instanceof User && ! $notifiable->hasRole('impresa');

        return $this->baseMailMessage("Sollecito sulla segnalazione #{$id}")
            ->line("Il comune chiede un aggiornamento sulla segnalazione #{$id}.")
            ->line(Str::limit($this->testo, 500))
            ->action($interno ? 'Apri segnalazione' : 'Accedi con SPID o CIE', $interno ? route('segnalazioni.show', $id) : route('accesso'))
            ->salutation('ProntoPA');
    }
}
