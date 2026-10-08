<?php

namespace App\Notifications\Deleghe;

use App\Models\Istituto;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class EsitoDelegaNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    /** @param string $esito approvata · rifiutata · revocata · scaduta · in_scadenza */
    public function __construct(
        public readonly string $esito,
        public readonly Istituto $istituto,
        public readonly ?Carbon $data = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $scuola = $this->istituto->descrizione;

        [$oggetto, $testo] = match ($this->esito) {
            'approvata' => ['delega approvata', "La segreteria di {$scuola} ha approvato la tua delega: ora puoi segnalare guasti per la scuola."],
            'rifiutata' => ['delega non approvata', "La segreteria di {$scuola} non ha approvato la tua richiesta di delega."],
            'revocata' => ['delega revocata', "La tua delega per {$scuola} è stata revocata."],
            'scaduta' => ['delega scaduta', "La tua delega per {$scuola} è scaduta perché la segreteria non l'ha confermata. Puoi presentare una nuova richiesta."],
            'in_scadenza' => ['delega in scadenza', "La tua delega per {$scuola} scade il ".$this->data?->format('d/m/Y').' e la segreteria non l\'ha ancora confermata: sollecitala.'],
            default => ['delega', "Aggiornamento sulla tua delega per {$scuola}."],
        };

        return $this->baseMailMessage('ProntoPA — '.ucfirst($oggetto))
            ->line($testo)
            ->action('Le mie deleghe', route('scuola.deleghe.index'))
            ->salutation('ProntoPA');
    }
}
