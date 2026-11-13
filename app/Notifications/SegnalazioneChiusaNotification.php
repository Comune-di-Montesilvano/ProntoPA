<?php

namespace App\Notifications;

use App\Models\Azione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use App\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SegnalazioneChiusaNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
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
        $mail = $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'Chiusa'))
            ->line('La tua segnalazione #' . $this->segnalazione->id_segnalazione . ' è stata chiusa ' . $this->daChi($notifiable) . '.');

        return $this->riepilogoSegnalazione($mail, $this->segnalazione)
            ->action('Apri segnalazione', route('segnalazioni.show', $this->segnalazione->id_segnalazione))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => implode("\n", [
                'Segnalazione chiusa',
                $this->rigaSegnalazione($this->segnalazione),
                'Stato finale: ' . ($this->segnalazione->stato?->descrizione ?? 'N/D'),
                'Chiusa ' . $this->daChi($notifiable),
            ]),
        ];
    }

    /** Il nome di chi ha chiuso solo al personale: scuole e cittadini leggono "dal comune" (spec §8). */
    private function daChi(object $notifiable): string
    {
        $interno = $notifiable instanceof User && $notifiable->hasAnyRole(['admin', 'gestore', 'operaio']);

        return $interno ? 'da ' . $this->attore->name : 'dal comune';
    }
}