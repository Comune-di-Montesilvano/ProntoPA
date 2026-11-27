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
use Illuminate\Support\Str;

/** Il comune, o chi ha aperto la segnalazione, chiede a chi ha il lavoro un aggiornamento. */
class SollecitoNotification extends Notification implements ShouldQueue, Raggruppabile
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;
    use Raggruppa;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly string $testo,
        public readonly bool $dalSegnalante = false,
    ) {}

    public function categoriaRiepilogo(): string
    {
        return 'solleciti';
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
        return 'Sollecito';
    }

    public function ancoraValida(object $notifiable): bool
    {
        return $this->segnalazioneAperta();
    }

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

        $mail = $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'Sollecito'))
            ->line($this->dalSegnalante
                ? "Chi ha aperto la segnalazione #{$id} chiede un aggiornamento."
                : "Il comune chiede un aggiornamento sulla segnalazione #{$id}.")
            ->line(Str::limit($this->testo, 500));

        return $this->riepilogoSegnalazione($mail, $this->segnalazione, perChiLavora: ! $this->dalSegnalante)
            ->action($interno ? 'Apri segnalazione' : 'Accedi con SPID o CIE', $interno ? route('segnalazioni.show', $id) : route('accesso'))
            ->salutation('ProntoPA');
    }
}
