<?php

namespace App\Notifications;

use App\Channels\TelegramChannel;
use App\Models\Azione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use App\Notifications\Riepilogo\Raggruppa;
use App\Notifications\Riepilogo\Raggruppabile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SegnalazioneStatoCambiato extends Notification implements ShouldQueue, Raggruppabile
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;
    use Raggruppa;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly Azione       $azione,
        public readonly User         $attore,
    ) {}

    public function categoriaRiepilogo(): string
    {
        return 'esiti';
    }

    public function segnalazioneRiepilogo(): int
    {
        return (int) $this->segnalazione->id_segnalazione;
    }

    public function rigaRiepilogo(object $notifiable): string
    {
        return $this->rigaSegnalazione($this->segnalazione).' — '.$this->azione->descrizione;
    }

    public function motivoRiepilogo(): string
    {
        return 'Aggiornamento';
    }

    public function ancoraValida(object $notifiable): bool
    {
        return $this->segnalazioneEsiste();
    }

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
        $url = route('segnalazioni.show', $this->segnalazione->id_segnalazione);

        $mail = $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'Aggiornamento'))
            ->line('La segnalazione **#' . $this->segnalazione->id_segnalazione . '** ha ricevuto un aggiornamento.')
            ->line('**Azione eseguita:** ' . $this->azione->descrizione);

        return $this->riepilogoSegnalazione($mail, $this->segnalazione)
            ->action('Visualizza segnalazione', $url)
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => implode("\n", [
                'Aggiornamento ' . $this->rigaSegnalazione($this->segnalazione),
                'Azione: ' . $this->azione->descrizione,
                'Nuovo stato: ' . ($this->segnalazione->stato?->descrizione ?? 'N/D'),
            ]),
        ];
    }
}
