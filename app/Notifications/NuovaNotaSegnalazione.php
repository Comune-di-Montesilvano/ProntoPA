<?php

namespace App\Notifications;

use App\Channels\TelegramChannel;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use App\Notifications\Riepilogo\Raggruppa;
use App\Notifications\Riepilogo\Raggruppabile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NuovaNotaSegnalazione extends Notification implements ShouldQueue, Raggruppabile
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;
    use Raggruppa;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly NotaSegnalazione $nota,
    ) {}

    public function categoriaRiepilogo(): string
    {
        return 'lavoro';
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
        return 'Nuova nota';
    }

    public function ancoraValida(object $notifiable): bool
    {
        return $this->segnalazioneEsiste();
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
        $mail = $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'Nuovo messaggio'))
            ->line('È stata aggiunta una nota alla segnalazione #' . $this->segnalazione->id_segnalazione . ':')
            ->line(Str::limit($this->nota->testo, 300));

        return $this->riepilogoSegnalazione($mail, $this->segnalazione)
            ->action('Apri segnalazione', route('segnalazioni.show', $this->segnalazione->id_segnalazione))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => implode("\n", [
                '📝 Nuova nota su ' . $this->rigaSegnalazione($this->segnalazione),
                Str::limit($this->nota->testo, 200),
            ]),
        ];
    }
}
