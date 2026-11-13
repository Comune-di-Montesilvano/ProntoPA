<?php

namespace App\Notifications;

use App\Models\Azione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use App\Channels\TelegramChannel;
use App\Models\Impostazione;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperatoreAssegnatoNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly Azione $azione,
        public readonly User $attore,
        public readonly ?string $nota = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if ($notifiable->routeNotificationForTelegram() && Impostazione::get('telegram_notifica_operatore_assegnazione', true)) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'Assegnata a te'))
            ->line('Ti è stato assegnato un lavoro da ' . $this->attore->name . '.');

        return $this->riepilogoSegnalazione($mail, $this->segnalazione, perChiLavora: true, nota: $this->nota)
            ->action('Apri segnalazione', route('segnalazioni.show', $this->segnalazione->id_segnalazione))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => implode("\n", [
                'Assegnata a te',
                $this->rigaSegnalazione($this->segnalazione),
                'Da: ' . $this->attore->name,
            ]),
        ];
    }
}