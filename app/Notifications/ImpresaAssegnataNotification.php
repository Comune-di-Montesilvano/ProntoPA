<?php

namespace App\Notifications;

use App\Models\Azione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ImpresaAssegnataNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly Azione $azione,
        public readonly User $attore,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (method_exists($notifiable, 'routeNotificationForTelegram') && $notifiable->routeNotificationForTelegram()) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $impresa = $this->segnalazione->appalto?->impresa?->ragione_sociale;

        return $this->baseMailMessage('Nuovo appalto assegnato per segnalazione #' . $this->segnalazione->id_segnalazione)
            ->line('La segnalazione #' . $this->segnalazione->id_segnalazione . ' è stata assegnata alla tua impresa.')
            ->line('Impresa: ' . ($impresa ?: 'N/D'))
            ->line('Stato corrente: ' . ($this->segnalazione->stato?->descrizione ?? 'N/D'))
            ->action('Apri segnalazione', route('segnalazioni.show', $this->segnalazione->id_segnalazione))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => implode("\n", [
                'Nuovo appalto assegnato',
                'Segnalazione #' . $this->segnalazione->id_segnalazione,
                'Impresa: ' . ($this->segnalazione->appalto?->impresa?->ragione_sociale ?? 'N/D'),
                'Stato: ' . ($this->segnalazione->stato?->descrizione ?? 'N/D'),
            ]),
        ];
    }
}