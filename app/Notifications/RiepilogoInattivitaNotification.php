<?php

namespace App\Notifications;

use App\Models\Segnalazione;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Ai supervisori, una volta al giorno: segnalazioni avvisate per inattività e archiviate. */
class RiepilogoInattivitaNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    /**
     * @param  list<Segnalazione>  $avvisate
     * @param  list<Segnalazione>  $archiviate
     */
    public function __construct(public readonly array $avvisate, public readonly array $archiviate) {}

    /** @return list<int> per TracciaNotificaInviata */
    public function segnalazioniTracciate(): array
    {
        return array_map(fn (Segnalazione $s) => $s->id_segnalazione, [...$this->avvisate, ...$this->archiviate]);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $messaggio = $this->baseMailMessage('Segnalazioni ferme: '.count($this->avvisate).' avvisate, '.count($this->archiviate).' archiviate');

        if ($this->avvisate) {
            $messaggio = $messaggio->line('**Avvisate** (chi ha aperto e chi ha in carico hanno ricevuto l\'avviso; senza aggiornamenti verranno archiviate):');
            foreach ($this->avvisate as $s) {
                $messaggio = $messaggio->line($this->riga($s));
            }
        }
        if ($this->archiviate) {
            $messaggio = $messaggio->line('**Archiviate per inattività** (si possono riaprire):');
            foreach ($this->archiviate as $s) {
                $messaggio = $messaggio->line($this->riga($s));
            }
        }

        return $messaggio->action('Apri le segnalazioni', route('gestione.dashboard'))->salutation('ProntoPA');
    }

    private function riga(Segnalazione $s): string
    {
        $titolo = $s->titolo_generato ?: Str::limit((string) $s->testo_segnalazione, 70);

        return "- [#{$s->id_segnalazione}](".route('segnalazioni.show', $s->id_segnalazione).") {$titolo}";
    }
}
