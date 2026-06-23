<?php

namespace App\Notifications;

use App\Channels\TelegramChannel;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DigestGestoreNotification extends Notification implements ShouldQueue
{
    use BuildsNotificationMailMessage;
    use Queueable;

    public function __construct(
        public readonly array $inRitardoIds,
        public readonly array $inScadenzaIds,
        public readonly array $nonAssegnateIds,
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
        $inRitardo    = $this->carica($this->inRitardoIds);
        $inScadenza   = $this->carica($this->inScadenzaIds);
        $nonAssegnate = $this->carica($this->nonAssegnateIds);

        $mail = $this->baseMailMessage('Digest segnalazioni — ' . now()->format('d/m/Y'));

        if ($inRitardo->isNotEmpty()) {
            $mail->line('🔴 In ritardo (' . $inRitardo->count() . '):');
            foreach ($inRitardo as $s) {
                $mail->line('#' . $s->id_segnalazione . ' — ' . \Illuminate\Support\Str::limit($s->testo_segnalazione, 60)
                    . ' (scaduta il ' . $s->data_scadenza_sla?->format('d/m') . ')');
            }
        }

        if ($inScadenza->isNotEmpty()) {
            $mail->line('🟡 In scadenza (' . $inScadenza->count() . '):');
            foreach ($inScadenza as $s) {
                $mail->line('#' . $s->id_segnalazione . ' — ' . \Illuminate\Support\Str::limit($s->testo_segnalazione, 60)
                    . ' (scade il ' . $s->data_scadenza_sla?->format('d/m H:i') . ')');
            }
        }

        if ($nonAssegnate->isNotEmpty()) {
            $mail->line('⚪ Nuove non assegnate (' . $nonAssegnate->count() . '):');
            foreach ($nonAssegnate as $s) {
                $mail->line('#' . $s->id_segnalazione . ' — ' . \Illuminate\Support\Str::limit($s->testo_segnalazione, 60));
            }
        }

        return $mail
            ->action('Apri la dashboard', route('gestione.dashboard'))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        $inRitardo    = $this->carica($this->inRitardoIds);
        $inScadenza   = $this->carica($this->inScadenzaIds);
        $nonAssegnate = $this->carica($this->nonAssegnateIds);

        $righe = ['📋 Digest ' . now()->format('d/m/Y')];

        foreach ([
            '🔴 In ritardo'   => $inRitardo,
            '🟡 In scadenza'  => $inScadenza,
            '⚪ Non assegnate' => $nonAssegnate,
        ] as $label => $gruppo) {
            if ($gruppo->isNotEmpty()) {
                $righe[] = $label . ': ' . $gruppo->pluck('id_segnalazione')->map(fn ($id) => '#' . $id)->implode(' ');
            }
        }

        return ['text' => implode("\n", $righe)];
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\Segnalazione> */
    private function carica(array $ids): \Illuminate\Support\Collection
    {
        if (empty($ids)) {
            return collect();
        }

        return \App\Models\Segnalazione::whereIn('id_segnalazione', $ids)
            ->orderByRaw('FIELD(id_segnalazione, ' . implode(',', array_map('intval', $ids)) . ')')
            ->get();
    }
}
