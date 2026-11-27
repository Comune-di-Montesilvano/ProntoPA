<?php

namespace App\Notifications;

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

/** Avvisa il comune che la ditta o chi ha segnalato ha scritto sulla segnalazione. */
class NotaDaEsternoNotification extends Notification implements ShouldQueue, Raggruppabile
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;
    use Raggruppa;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly NotaSegnalazione $nota,
        public readonly string $mittente,
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
        return 'Nuovo messaggio';
    }

    public function ancoraValida(object $notifiable): bool
    {
        return $this->segnalazioneEsiste();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $id = $this->segnalazione->id_segnalazione;

        $mail = $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'Nuovo messaggio'))
            ->line("{$this->mittente} ha scritto sulla segnalazione #{$id}:")
            ->line(Str::limit($this->nota->testo, 500));

        return $this->riepilogoSegnalazione($mail, $this->segnalazione)
            ->action('Apri segnalazione', route('segnalazioni.show', $id))
            ->salutation('ProntoPA');
    }
}
