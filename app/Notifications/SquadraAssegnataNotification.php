<?php

namespace App\Notifications;

use App\Channels\TelegramChannel;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SquadraAssegnataNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly int $idSegnalazione,
        public readonly int $idSquadra,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if ($notifiable->routeNotificationForTelegram()) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $segnalazione = Segnalazione::findOrFail($this->idSegnalazione);
        $squadra      = Squadra::findOrFail($this->idSquadra);

        return $this->baseMailMessage('Nuovo lavoro per la squadra ' . $squadra->nome)
            ->line('Alla squadra "' . $squadra->nome . '" è stata assegnata la segnalazione #' . $segnalazione->id_segnalazione . '.')
            ->line(\Illuminate\Support\Str::limit($segnalazione->testo_segnalazione, 120))
            ->line('Priorità: ' . $segnalazione->label_priorita)
            ->action('Apri segnalazione', route('segnalazioni.show', $segnalazione->id_segnalazione))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        $segnalazione = Segnalazione::findOrFail($this->idSegnalazione);
        $squadra      = Squadra::findOrFail($this->idSquadra);

        return [
            'text' => implode("\n", [
                '👷 Nuovo lavoro squadra ' . $squadra->nome,
                '#' . $segnalazione->id_segnalazione,
                'Priorità: ' . $segnalazione->label_priorita,
            ]),
        ];
    }
}
