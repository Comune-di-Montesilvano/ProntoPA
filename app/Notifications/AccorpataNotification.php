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

/**
 * Una nuova segnalazione è stata accorpata a una già aperta.
 * Variante "lavoro": a chi ha in mano la vecchia (è di nuovo sollecitata).
 * Variante "segnalante": a chi ha mandato la nuova (segua la vecchia).
 */
class AccorpataNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly Segnalazione $destinazione,
        public readonly Segnalazione $nuova,
        public readonly string $variante,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $id = $this->destinazione->id_segnalazione;

        if ($this->variante === 'segnalante') {
            $messaggio = $this->baseMailMessage("La tua segnalazione #{$this->nuova->id_segnalazione}")
                ->line("Lo stesso problema era già stato segnalato: abbiamo unito la tua segnalazione alla #{$id}.")
                ->line('Riceverai gli aggiornamenti di quella.');

            return $notifiable instanceof User
                ? $messaggio->action('Segui la segnalazione', route('segnalazioni.show', $id))->salutation('ProntoPA')
                : $messaggio->salutation('ProntoPA');
        }

        $interno = $notifiable instanceof User && ! $notifiable->hasRole('impresa');
        $volte = $this->destinazione->volteSegnalata();

        return $this->baseMailMessage("Segnalazione #{$id} segnalata di nuovo")
            ->line("La segnalazione #{$id} è stata segnalata di nuovo (in tutto {$volte} volte).")
            ->line(Str::limit((string) $this->nuova->testo_segnalazione, 300))
            ->action($interno ? 'Apri segnalazione' : 'Accedi con SPID o CIE', $interno ? route('segnalazioni.show', $id) : route('accesso'))
            ->salutation('ProntoPA');
    }
}
