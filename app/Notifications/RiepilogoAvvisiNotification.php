<?php

namespace App\Notifications;

use App\Channels\TelegramChannel;
use App\Models\Impostazione;
use App\Models\User;
use App\Notifications\Concerns\BuildsNotificationMailMessage;
use App\Services\Avvisi\Composizione;
use App\Services\Avvisi\Voce;
use App\Support\Avvisi\Categorie;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Riepilogo di più avvisi (e, al mattino, quadro di oggi) in un solo messaggio. Non è in coda:
 * la invia il job del riepilogo, che deve conoscere l'esito (`esigeEsito`) prima di svuotare l'attesa.
 */
class RiepilogoAvvisiNotification extends Notification
{
    use BuildsNotificationMailMessage;

    public const MAX_PULSANTI = 10;

    public const MAX_TESTO_TELEGRAM = 4000;

    private const TITOLI_QUADRO = ['nuove' => 'Nuove', 'da_fare' => 'Da fare', 'in_ritardo' => 'In ritardo'];

    /** Il canale Telegram deve segnalare l'invio fallito: il riepilogo non si perde. */
    public bool $esigeEsito = true;

    public function __construct(
        public readonly Composizione $composizione,
        public readonly bool $mattino,
    ) {}

    public function via(object $notifiable): array
    {
        $canali = ['mail'];

        if (method_exists($notifiable, 'routeNotificationForTelegram')
            && $notifiable->routeNotificationForTelegram()
            && Impostazione::get('telegram_riepiloghi', true)) {
            $canali[] = TelegramChannel::class;
        }

        return $canali;
    }

    /** @return list<int> */
    public function segnalazioniTracciate(): array
    {
        return array_values(array_unique(array_map(
            fn (Voce $v) => (int) $v->segnalazione->id_segnalazione,
            $this->tutteLeVoci(),
        )));
    }

    /** @return array<int, list<string>> id segnalazione => class basename degli avvisi originali */
    public function tipiTracciati(): array
    {
        $tipi = [];
        foreach ($this->tutteLeVoci() as $voce) {
            if ($voce->tipi === []) {
                continue;
            }
            $id = (int) $voce->segnalazione->id_segnalazione;
            $tipi[$id] = array_values(array_unique([...($tipi[$id] ?? []), ...$voce->tipi]));
        }

        return $tipi;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c = $this->composizione;
        $mail = $this->baseMailMessage('Riepilogo ProntoPA — '.$c->totaleSegnalazioni().' segnalazioni');

        if ($this->mattino && ! $c->quadroVuoto()) {
            $mail->line('## Il quadro di oggi');
            foreach (self::TITOLI_QUADRO as $chiave => $titolo) {
                $voci = $c->quadro[$chiave] ?? [];
                if ($voci === []) {
                    continue;
                }
                $mail->line('**'.$titolo.' ('.count($voci).')**');
                foreach ($voci as $voce) {
                    $mail->line($this->rigaLink($voce, $notifiable));
                }
            }
        }

        foreach ($c->sezioni as $categoria => $voci) {
            $mail->line('**'.Categorie::etichetta($categoria).' ('.count($voci).')**');
            foreach ($voci as $voce) {
                $mail->line($this->rigaLink($voce, $notifiable));
            }
        }

        return $mail
            ->action('Apri ProntoPA', $notifiable instanceof User ? route('dashboard') : route('accesso'))
            ->salutation('ProntoPA');
    }

    public function toTelegram(object $notifiable): array
    {
        $c = $this->composizione;
        $righe = ['📋 Riepilogo ProntoPA — '.$c->totaleSegnalazioni().' segnalazioni'];
        $mostrate = [];

        if ($this->mattino && ! $c->quadroVuoto()) {
            $righe[] = '';
            $righe[] = 'Il quadro di oggi';
            foreach (self::TITOLI_QUADRO as $chiave => $titolo) {
                $voci = $c->quadro[$chiave] ?? [];
                if ($voci === []) {
                    continue;
                }
                $righe[] = $titolo.' ('.count($voci).')';
                foreach ($voci as $voce) {
                    $righe[] = '• '.$this->testoRiga($voce);
                    $mostrate[(int) $voce->segnalazione->id_segnalazione] = true;
                }
            }
        }

        foreach ($c->sezioni as $categoria => $voci) {
            $righe[] = '';
            $righe[] = Categorie::etichetta($categoria).' ('.count($voci).')';
            foreach ($voci as $voce) {
                $righe[] = '• '.$this->testoRiga($voce);
                $mostrate[(int) $voce->segnalazione->id_segnalazione] = true;
            }
        }

        $ids = array_keys($mostrate);
        $tastiera = [];
        foreach (array_slice($ids, 0, self::MAX_PULSANTI) as $id) {
            $tastiera[] = [['text' => '📂 Apri #'.$id, 'callback_data' => 'apri:'.$id]];
        }
        $altre = count($ids) - self::MAX_PULSANTI;
        if ($altre > 0) {
            $righe[] = '';
            $righe[] = 'e altre '.$altre;
        }

        $testo = implode("\n", $righe);
        if (mb_strlen($testo) > self::MAX_TESTO_TELEGRAM) {
            $testo = mb_substr($testo, 0, self::MAX_TESTO_TELEGRAM - 1).'…';
        }

        $messaggio = ['text' => $testo];
        if ($tastiera !== []) {
            $messaggio['inline_keyboard'] = $tastiera;
        }

        return $messaggio;
    }

    /** @return list<Voce> */
    private function tutteLeVoci(): array
    {
        $voci = [];
        foreach ([...array_values($this->composizione->quadro), ...array_values($this->composizione->sezioni)] as $gruppo) {
            foreach ($gruppo as $voce) {
                $voci[] = $voce;
            }
        }

        return $voci;
    }

    /** Riga della voce: testo, «· a rischio» (quadro «da fare», scadenza entro 48 ore) e motivi. */
    private function testoRiga(Voce $voce): string
    {
        $testo = $voce->riga;

        if ($voce->categoria === 'da_fare' && $this->aRischio($voce)) {
            $testo .= ' · a rischio';
        }
        if ($voce->motivi !== []) {
            $testo .= ' — '.implode(' · ', $voce->motivi);
        }

        return $testo;
    }

    private function aRischio(Voce $voce): bool
    {
        if (! $voce->segnalazione->data_scadenza_sla) {
            return false;
        }
        $scadenza = Carbon::parse($voce->segnalazione->data_scadenza_sla);

        return $scadenza->greaterThanOrEqualTo(now()) && $scadenza->lessThanOrEqualTo(now()->addHours(48));
    }

    private function rigaLink(Voce $voce, object $notifiable): string
    {
        $url = $notifiable instanceof AnonymousNotifiable
            ? route('accesso')
            : route('segnalazioni.show', $voce->segnalazione->id_segnalazione);

        return '- ['.$this->testoRiga($voce).']('.$url.')';
    }
}
