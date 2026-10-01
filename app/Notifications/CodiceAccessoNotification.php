<?php

namespace App\Notifications;

use App\Models\Impostazione;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CodiceAccessoNotification extends Notification
{
    public function __construct(
        public readonly string $codice,
        public readonly int $minuti,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ente = Impostazione::get('ente_nome', 'ProntoPA');

        return (new MailMessage)
            ->subject("Codice di accesso — {$ente}")
            ->line('Il tuo codice di accesso è:')
            ->line("**{$this->codice}**")
            ->line("Scade tra {$this->minuti} minuti.")
            ->line("Se non hai appena tentato di accedere a ProntoPA, ignora questa email e avvisa {$ente}.");
    }
}
