<?php

namespace App\Notifications;

use App\Models\Segnalazione;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use App\Notifications\Riepilogo\Raggruppa;
use App\Notifications\Riepilogo\Raggruppabile;
use App\Services\Avvisi\QuadroMattino;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Ai gestori della tipologia: nuova segnalazione, o spostata nella loro tipologia. */
class NuovaSegnalazioneNotification extends Notification implements ShouldQueue, Raggruppabile
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;
    use Raggruppa;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly bool $riclassificata = false,
    ) {
        $this->afterCommit();
    }

    public function categoriaRiepilogo(): string
    {
        return 'nuove';
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
        return $this->riclassificata ? 'Riclassificata' : 'Nuova';
    }

    /** Vale finché la segnalazione è da smistare: presa in carico o affidata non è più «nuova». */
    public function ancoraValida(object $notifiable): bool
    {
        $s = $this->segnalazioneAttuale();

        return $s !== null && QuadroMattino::daSmistare($s);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->segnalazione;
        $tipologia = (string) ($s->tipologia()->value('descrizione') ?? 'tipologia non indicata');
        $oggetto = $this->oggettoSegnalazione($s, $this->riclassificata ? 'Riclassificata' : 'Nuova segnalazione');

        $mail = $this->baseMailMessage($oggetto)
            ->line($this->riclassificata
                ? "La segnalazione è stata spostata in una tipologia di cui ti occupi ({$tipologia})."
                : "È arrivata una nuova segnalazione di una tipologia di cui ti occupi ({$tipologia}).");

        return $this->riepilogoSegnalazione($mail, $s, perChiLavora: true)
            ->action('Apri segnalazione', route('segnalazioni.show', $s->id_segnalazione))
            ->salutation('ProntoPA');
    }
}
