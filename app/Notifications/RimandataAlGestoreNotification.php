<?php

namespace App\Notifications;

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

/** Operaio o ditta rimandano il lavoro al comune: torna da riassegnare. */
class RimandataAlGestoreNotification extends Notification implements ShouldQueue, Raggruppabile
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;
    use Raggruppa;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly string $mittente,
        public readonly string $nota,
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
        return 'Rimandata al comune';
    }

    public function ancoraValida(object $notifiable): bool
    {
        return $this->segnalazioneAperta();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $id = $this->segnalazione->id_segnalazione;

        $mail = $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'Rimandata al comune'))
            ->line("{$this->mittente} ha rimandato al comune la segnalazione #{$id}.")
            ->line('Motivo: '.Str::limit($this->nota, 1000))
            ->line('Va riassegnata.');

        return $this->riepilogoSegnalazione($mail, $this->segnalazione)
            ->action('Apri segnalazione', route('segnalazioni.show', $id))
            ->salutation('ProntoPA');
    }
}
