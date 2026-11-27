<?php

namespace App\Notifications;

use App\Channels\TelegramChannel;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use App\Notifications\Riepilogo\Raggruppa;
use App\Notifications\Riepilogo\Raggruppabile;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SquadraAssegnataNotification extends Notification implements ShouldQueue, Raggruppabile
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;
    use Raggruppa;

    public function __construct(
        public readonly int $idSegnalazione,
        public readonly int $idSquadra,
        public readonly ?string $nota = null,
    ) {}

    public function categoriaRiepilogo(): string
    {
        return 'assegnazioni';
    }

    public function segnalazioneRiepilogo(): int
    {
        return $this->idSegnalazione;
    }

    public function rigaRiepilogo(object $notifiable): string
    {
        $segnalazione = Segnalazione::find($this->idSegnalazione);

        return $segnalazione ? $this->rigaSegnalazione($segnalazione) : '#'.$this->idSegnalazione;
    }

    public function motivoRiepilogo(): string
    {
        return 'Assegnata alla tua squadra';
    }

    public function ancoraValida(object $notifiable): bool
    {
        $s = $this->segnalazioneAttuale();
        if ($s === null || $s->statoEnum()->isTerminale() || (int) $s->id_squadra_assegnata !== $this->idSquadra) {
            return false;
        }

        return $notifiable instanceof User
            && (int) Squadra::whereKey($this->idSquadra)->value('id_caposquadra') === $notifiable->id;
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
        $segnalazione = Segnalazione::find($this->idSegnalazione);
        $squadra      = Squadra::find($this->idSquadra);

        if (! $segnalazione || ! $squadra) {
            return $this->baseMailMessage('Segnalazione non più disponibile')
                ->line('La segnalazione collegata a questa notifica non è più disponibile.');
        }

        $mail = $this->baseMailMessage($this->oggettoSegnalazione($segnalazione, 'Assegnata alla tua squadra'))
            ->line('Alla squadra "' . $squadra->nome . '" è stato assegnato un lavoro: affidalo a uno dei membri.')
            ->line('Priorità: ' . $segnalazione->label_priorita);

        return $this->riepilogoSegnalazione($mail, $segnalazione, perChiLavora: true, nota: $this->nota)
            ->action('Apri segnalazione', route('segnalazioni.show', $segnalazione->id_segnalazione))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        $segnalazione = Segnalazione::find($this->idSegnalazione);
        $squadra      = Squadra::find($this->idSquadra);

        if (! $segnalazione || ! $squadra) {
            return ['text' => 'Segnalazione non più disponibile.'];
        }

        return [
            'text' => implode("\n", [
                '👷 Nuovo lavoro squadra ' . $squadra->nome,
                $this->rigaSegnalazione($segnalazione),
                'Priorità: ' . $segnalazione->label_priorita,
            ]),
        ];
    }
}
