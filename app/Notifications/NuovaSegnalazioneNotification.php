<?php

namespace App\Notifications;

use App\Models\Segnalazione;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Ai gestori della tipologia: nuova segnalazione, o spostata nella loro tipologia. */
class NuovaSegnalazioneNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
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
        $oggetto = $this->riclassificata
            ? "Segnalazione #{$s->id_segnalazione} riclassificata in {$tipologia}"
            : "Nuova segnalazione #{$s->id_segnalazione} – {$tipologia}";

        $mail = $this->baseMailMessage($oggetto)
            ->line($this->riclassificata
                ? "La segnalazione è stata spostata in una tipologia di cui ti occupi ({$tipologia})."
                : "È arrivata una nuova segnalazione di una tipologia di cui ti occupi ({$tipologia}).")
            ->line('**'.$s->titoloLeggibile().'**');

        if ($s->plesso) {
            $mail->line('Dove: '.$s->plesso->nome);
        }

        return $mail
            ->action('Apri segnalazione', route('segnalazioni.show', $s->id_segnalazione))
            ->salutation('ProntoPA');
    }
}
