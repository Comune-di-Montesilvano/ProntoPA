<?php

namespace App\Notifications;

use App\Models\Azione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Notifications\Concerns\DescriveSegnalazione;
use App\Channels\TelegramChannel;
use App\Notifications\Riepilogo\Raggruppa;
use App\Notifications\Riepilogo\Raggruppabile;
use App\Services\Segnalazioni\DestinatariAssegnatario;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class ImpresaAssegnataNotification extends Notification implements ShouldQueue, Raggruppabile
{
    use BuildsNotificationMailMessage;
    use DescriveSegnalazione;
    use Queueable;
    use Raggruppa;

    public function __construct(
        public readonly Segnalazione $segnalazione,
        public readonly Azione $azione,
        public readonly User $attore,
        public readonly ?string $nota = null,
    ) {}

    public function categoriaRiepilogo(): string
    {
        return 'assegnazioni';
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
        return 'Affidata alla ditta';
    }

    public function ancoraValida(object $notifiable): bool
    {
        $s = $this->segnalazioneAttuale();
        if ($s === null || $s->statoEnum()->isTerminale()) {
            return false;
        }

        $destinatari = DestinatariAssegnatario::per($s);
        if ($notifiable instanceof User) {
            return $destinatari['utenti']->contains(fn (User $u) => $u->id === $notifiable->id);
        }
        $route = $notifiable instanceof AnonymousNotifiable ? ($notifiable->routes['mail'] ?? '') : '';
        $email = is_string($route) ? mb_strtolower(trim($route)) : '';

        return $email !== '' && in_array($email, $destinatari['email'], true);
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
        $impresa = $this->segnalazione->appalto?->impresa?->ragione_sociale;

        $mail = $this->baseMailMessage($this->oggettoSegnalazione($this->segnalazione, 'Lavoro affidato alla ditta'))
            ->line('Il comune ha affidato un lavoro alla tua impresa (' . ($impresa ?: 'N/D') . ').');

        return $this->riepilogoSegnalazione($mail, $this->segnalazione, perChiLavora: true, nota: $this->nota)
            ->action('Accedi con SPID o CIE', route('accesso'))
            ->line('Possono accedere solo le persone delegate dal comune per la tua impresa.')
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => implode("\n", [
                'Lavoro affidato alla ditta',
                $this->rigaSegnalazione($this->segnalazione),
                'Impresa: ' . ($this->segnalazione->appalto?->impresa?->ragione_sociale ?? 'N/D'),
            ]),
        ];
    }
}