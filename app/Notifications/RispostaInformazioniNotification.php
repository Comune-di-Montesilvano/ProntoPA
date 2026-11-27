<?php

namespace App\Notifications;

use App\Models\Segnalazione;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use App\Notifications\Riepilogo\Raggruppa;
use App\Notifications\Riepilogo\Raggruppabile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Chi ha segnalato ha risposto alla richiesta di informazioni: la segnalazione è tornata dov'era. */
class RispostaInformazioniNotification extends Notification implements ShouldQueue, Raggruppabile
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;
    use Raggruppa;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly string $chi,
        public readonly ?string $testo = null,
        public readonly bool $senzaRisposta = false,
    ) {
        $this->afterCommit();
    }

    public function categoriaRiepilogo(): string
    {
        return 'lavoro';
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
        return $this->senzaRisposta ? 'Nessuna risposta' : 'Hanno risposto';
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

        $mail = $this->senzaRisposta
            ? $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'Nessuna risposta'))
                ->line("Nessuna risposta entro il termine alla richiesta di informazioni sulla segnalazione #{$id}: torna a te.")
            : $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'Hanno risposto'))
                ->line("{$this->chi} ha risposto alla richiesta di informazioni sulla segnalazione #{$id}.");
        if ($this->testo !== null && trim($this->testo) !== '') {
            $mail->line(Str::limit($this->testo, 1000));
        }
        $mail->line('La segnalazione è tornata dov\'era e la scadenza riparte.');

        return $this->riepilogoSegnalazione($mail, $this->segnalazione)
            ->action('Apri segnalazione', route('segnalazioni.show', $id))
            ->salutation('ProntoPA');
    }
}
