<?php

namespace App\Notifications;

use App\Models\Segnalazione;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use Carbon\CarbonInterface;
use App\Notifications\Riepilogo\Raggruppa;
use App\Notifications\Riepilogo\Raggruppabile;
use App\Enums\SegnalazioneStato;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Il comune chiede informazioni a chi ha segnalato: rispondere fa ripartire il lavoro. */
class RichiestaInformazioniNotification extends Notification implements ShouldQueue, Raggruppabile
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;
    use Raggruppa;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly string $testo,
        public readonly CarbonInterface $termine,
        public readonly bool $promemoria = false,
    ) {
        $this->afterCommit();
    }

    public function categoriaRiepilogo(): string
    {
        return 'richieste';
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
        return $this->promemoria ? 'Promemoria: informazioni' : 'Ti chiediamo informazioni';
    }

    public function ancoraValida(object $notifiable): bool
    {
        return $this->segnalazioneAttuale()?->statoEnum() === SegnalazioneStato::ATTESA_RISPOSTA;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $id = $this->segnalazione->id_segnalazione;

        $mail = $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, $this->promemoria ? 'Promemoria: ti chiediamo informazioni' : 'Ti chiediamo informazioni'))
            ->line("Per andare avanti con la segnalazione #{$id} ci serve sapere:")
            ->line(Str::limit($this->testo, 2000))
            ->line('Rispondi entro '.$this->termine->copy()->locale('it')->isoFormat('dddd D MMMM').'.'
                .($this->promemoria ? ' Senza risposta la segnalazione potrebbe essere chiusa.' : ''));

        return $this->riepilogoSegnalazione($mail, $this->segnalazione)
            ->action('Rispondi', route('segnalazioni.show', $id))
            ->salutation('ProntoPA');
    }
}
