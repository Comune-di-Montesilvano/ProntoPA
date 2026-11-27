<?php

namespace App\Listeners;

use App\Models\AvvisoInAttesa;
use App\Models\User;
use App\Notifications\Riepilogo\Raggruppabile;
use App\Support\Avvisi\Categorie;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Le notifiche raggruppabili non partono subito: finiscono in `avvisi_in_attesa` e le spedisce il
 * riepilogo (ora | giorno). Scatta per canale; la riga è una per notifica e destinatario (unique), i
 * canali successivi dello stesso invio sono solo annullati. Il reinvio dal riepilogo (`daRiepilogo`) passa.
 */
class IntercettaAvvisi
{
    public function handle(NotificationSending $evento): ?bool
    {
        $notifica = $evento->notification;
        if (! $notifica instanceof Raggruppabile || ! $notifica instanceof Notification) {
            return null;
        }
        if (property_exists($notifica, 'daRiepilogo') && $notifica->daRiepilogo) {
            return null;
        }

        $destinatario = self::destinatario($evento->notifiable);
        if ($destinatario === null) {
            return null;
        }

        if (blank($notifica->id)) {
            $notifica->id = (string) Str::uuid();
        }

        $categoria = $notifica->categoriaRiepilogo();
        $adesso = now();

        AvvisoInAttesa::query()->insertOrIgnore([
            'id_notifica'       => (string) $notifica->id,
            'destinatario_tipo' => $destinatario['tipo'],
            'id_utente'         => $destinatario['id_utente'],
            'email'             => $destinatario['email'],
            'categoria'         => $categoria,
            'ritmo'             => Categorie::ritmo($categoria),
            'id_segnalazione'   => $notifica->segnalazioneRiepilogo(),
            'tipo'              => class_basename($notifica),
            'notifica'          => serialize(self::senzaStatoCoda($notifica)),
            'tentativi'         => 0,
            'created_at'        => $adesso,
            'updated_at'        => $adesso,
        ]);

        return false;
    }

    /** @return array{tipo: string, id_utente: int, email: string}|null */
    private static function destinatario(object $notifiable): ?array
    {
        if ($notifiable instanceof User) {
            return ['tipo' => AvvisoInAttesa::TIPO_UTENTE, 'id_utente' => (int) $notifiable->id, 'email' => ''];
        }

        if ($notifiable instanceof AnonymousNotifiable) {
            $route = $notifiable->routes['mail'] ?? null;
            // Notification::route('mail', [indirizzo => nome]) o lista di indirizzi: il primo.
            if (is_array($route)) {
                $primo = array_key_first($route);
                $route = is_string($primo) ? $primo : ($route[$primo] ?? null);
            }
            $email = mb_strtolower(trim(is_string($route) ? $route : ''));

            return $email === '' ? null : ['tipo' => AvvisoInAttesa::TIPO_EMAIL, 'id_utente' => 0, 'email' => mb_substr($email, 0, 255)];
        }

        return null;
    }

    /** Copia da conservare: senza coda/ritardo/middleware dell'invio in corso, che al reinvio non valgono. */
    private static function senzaStatoCoda(Notification $notifica): Notification
    {
        $copia = clone $notifica;
        // Proprietà del trait Queueable.
        foreach (['connection' => null, 'queue' => null, 'delay' => null, 'middleware' => [], 'chained' => []] as $proprieta => $vuoto) {
            if (property_exists($copia, $proprieta)) {
                $copia->{$proprieta} = $vuoto;
            }
        }

        return $copia;
    }
}
