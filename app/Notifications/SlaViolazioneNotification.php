<?php

namespace App\Notifications;

use App\Channels\TelegramChannel;
use App\Models\Segnalazione;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use App\Notifications\Riepilogo\Raggruppa;
use App\Notifications\Riepilogo\Raggruppabile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SlaViolazioneNotification extends Notification implements ShouldQueue, Raggruppabile
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;
    use Raggruppa;

    public function __construct(
        public readonly Segnalazione $segnalazione,
    ) {}

    public function categoriaRiepilogo(): string
    {
        return 'scadenze';
    }

    public function segnalazioneRiepilogo(): int
    {
        return (int) $this->segnalazione->id_segnalazione;
    }

    public function rigaRiepilogo(object $notifiable): string
    {
        return $this->rigaSegnalazione($this->segnalazione);
    }

    public function motivoRiepilogo(): string
    {
        return 'Scaduta';
    }

    public function ancoraValida(object $notifiable): bool
    {
        $s = $this->segnalazioneAttuale();

        return $s !== null && ! $s->statoEnum()->isTerminale() && (bool) $s->sla_violato;
    }

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
        $mail = $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'Scaduta'))
            ->line('La segnalazione #' . $this->segnalazione->id_segnalazione . ' ha superato la scadenza.')
            ->line('Priorità: ' . $this->segnalazione->label_priorita);

        return $this->riepilogoSegnalazione($mail, $this->segnalazione, perChiLavora: true)
            ->action('Apri segnalazione', route('segnalazioni.show', $this->segnalazione->id_segnalazione))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => implode("\n", [
                '🚨 Scaduta',
                $this->rigaSegnalazione($this->segnalazione),
                'Priorità: ' . $this->segnalazione->label_priorita,
                'Scadenza: ' . $this->segnalazione->data_scadenza_sla?->format('d/m/Y H:i'),
            ]),
        ];
    }
}
