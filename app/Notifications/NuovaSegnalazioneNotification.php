<?php

namespace App\Notifications;

use App\Models\Segnalazione;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Ai gestori della tipologia: nuova segnalazione, o spostata nella loro tipologia. */
class NuovaSegnalazioneNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly bool $riclassificata = false,
    ) {
        $this->afterCommit();
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
