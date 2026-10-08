<?php

namespace App\Notifications\Deleghe;

use App\Models\Istituto;
use App\Models\User;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** Alla casella istituzionale della segreteria: un solo link, nessun pulsante d'azione nell'email. */
class RichiestaDelegaNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    /** @param list<string> $plessi vuoto = tutto l'istituto */
    public function __construct(
        public readonly User $richiedente,
        public readonly Istituto $istituto,
        public readonly array $plessi,
        public readonly string $url,
        public readonly Carbon $scadenza,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ambito = $this->plessi === []
            ? $this->istituto->descrizione
            : $this->istituto->descrizione.' — '.implode(', ', $this->plessi);

        return $this->baseMailMessage('ProntoPA — Richiesta di delega a segnalare guasti — '.$this->richiedente->name)
            ->line("**{$this->richiedente->name}** (codice fiscale {$this->richiedente->codice_fiscale}, email {$this->richiedente->email}), identificato con SPID/CIE, chiede di poter segnalare guasti e richieste di manutenzione all'ente per conto di **{$ambito}**.")
            ->line('Se questa persona lavora nella vostra scuola ed è autorizzata, approvate la richiesta. Se non la conoscete, rifiutatela.')
            ->line('Per decidere apri questo link: '.$this->url)
            ->line('Il link scade il '.$this->scadenza->format('d/m/Y').'. Non serve registrarsi a ProntoPA.')
            ->salutation('ProntoPA');
    }
}
