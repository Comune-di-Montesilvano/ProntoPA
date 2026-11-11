<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Services\Auth\EsitoSyncAd;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SyncAdFallitaNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly string $stato,
        public readonly string $motivo,
    ) {}

    public static function da(EsitoSyncAd $esito): self
    {
        return new self($esito->stato, (string) $esito->motivo);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $titolo = $this->stato === EsitoSyncAd::BLOCCATA
            ? 'Sincronizzazione utenti AD bloccata'
            : 'Sincronizzazione utenti AD fallita';

        return $this->baseMailMessage($titolo)
            ->line($this->motivo)
            ->line('Gli utenti di ProntoPA non sono stati modificati. Controlla la configurazione AD e i gruppi, poi usa "Sincronizza ora" in Admin → Utenti.')
            ->action('Apri Admin → Utenti', route('admin.utenti.index'));
    }
}
