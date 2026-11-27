<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * Un avviso tenuto da parte per il riepilogo, per un destinatario.
 * Destinatario: `utente` (`id_utente`, `email` = '') oppure `email` (`id_utente` = 0, `email` valorizzata);
 * mai NULL, perché l'unique a DB non distingue i NULL.
 */
class AvvisoInAttesa extends Model
{
    protected $table = 'avvisi_in_attesa';

    public const TIPO_UTENTE = 'utente';

    public const TIPO_EMAIL = 'email';

    protected $fillable = [
        'id_notifica', 'destinatario_tipo', 'id_utente', 'email', 'categoria', 'ritmo',
        'id_segnalazione', 'tipo', 'notifica', 'giro', 'tentativi', 'inviata_at', 'scartata_at',
    ];

    protected function casts(): array
    {
        return [
            'inviata_at'  => 'datetime',
            'scartata_at' => 'datetime',
        ];
    }

    public function scopeInAttesa(Builder $q): Builder
    {
        return $q->whereNull('inviata_at')->whereNull('scartata_at');
    }

    public function scopeDelRitmo(Builder $q, string $ritmo): Builder
    {
        return $q->where('ritmo', $ritmo);
    }

    public function chiaveDestinatario(): string
    {
        return $this->destinatario_tipo === self::TIPO_UTENTE
            ? 'u:'.$this->id_utente
            : 'e:'.mb_strtolower(trim((string) $this->email));
    }

    /** Utente attivo o destinatario anonimo via email; null se non c'è più o è disattivato. */
    public function notificabile(): User|AnonymousNotifiable|null
    {
        return self::notificabileDaChiave($this->chiaveDestinatario());
    }

    /** Dalla chiave del destinatario (`u:<id>` | `e:<email>`): utente attivo o rotta email; null altrimenti. */
    public static function notificabileDaChiave(string $chiave): User|AnonymousNotifiable|null
    {
        if (str_starts_with($chiave, 'u:')) {
            $id = (int) substr($chiave, 2);
            if ($id <= 0) {
                return null;
            }
            $utente = User::find($id);

            return $utente && $utente->attivo !== false ? $utente : null;
        }

        if (str_starts_with($chiave, 'e:')) {
            $email = trim(substr($chiave, 2));

            return $email !== '' ? NotificationFacade::route('mail', $email) : null;
        }

        return null;
    }

    /** La notifica serializzata, o null se non si riesce a ricostruirla (classe sparita, dati corrotti). */
    public function notifica(): ?Notification
    {
        try {
            $notifica = unserialize($this->notifica);

            return $notifica instanceof Notification ? $notifica : null;
        } catch (Throwable) {
            return null;
        }
    }
}
