<?php

namespace App\Notifications;

use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use App\Notifications\Riepilogo\Raggruppa;
use App\Notifications\Riepilogo\Raggruppabile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Archiviata perché nessuno l'ha aggiornata dopo l'avviso di inattività. */
class ArchiviataInattivitaNotification extends Notification implements ShouldQueue, Raggruppabile
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;
    use Raggruppa;

    public function __construct(public readonly Segnalazione $segnalazione) {}

    public function categoriaRiepilogo(): string
    {
        return 'inattivita';
    }

    public function segnalazioneRiepilogo(): int
    {
        return (int) $this->segnalazione->id_segnalazione;
    }

    public function rigaRiepilogo(object $notifiable): string
    {
        return $this->rigaSegnalazione($this->segnalazione);
    }

    public function motivoRiepilogo(): string
    {
        return 'Archiviata per inattività';
    }

    public function ancoraValida(object $notifiable): bool
    {
        return $this->segnalazioneEsiste();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $id = $this->segnalazione->id_segnalazione;
        $interno = $notifiable instanceof User && ! $notifiable->hasRole('impresa');

        $mail = $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'Archiviata per inattività'))
            ->line("La segnalazione #{$id} è stata archiviata: nessun aggiornamento dopo l'avviso di inattività.")
            ->line('Se il problema c\'è ancora, scrivi al comune o apri una nuova segnalazione.');

        return $this->riepilogoSegnalazione($mail, $this->segnalazione)
            ->action($interno ? 'Apri segnalazione' : 'Accedi con SPID o CIE', $interno ? route('segnalazioni.show', $id) : route('accesso'))
            ->salutation('ProntoPA');
    }
}
