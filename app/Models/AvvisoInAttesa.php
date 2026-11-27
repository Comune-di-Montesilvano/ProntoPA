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
            : 'e:'.mb_strtolower((string) $this->email);
    }

    /** Utente attivo o destinatario anonimo via email; null se non c'è più o è disattivato. */
    public function notificabile(): User|AnonymousNotifiable|null
    {
        if ($this->destinatario_tipo === self::TIPO_UTENTE) {
            if (! $this->id_utente) {
                return null;
            }
            $utente = User::find($this->id_utente);

            return $utente && $utente->attivo !== false ? $utente : null;
        }

        if ($this->destinatario_tipo === self::TIPO_EMAIL && (string) $this->email !== '') {
            return NotificationFacade::route('mail', $this->email);
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
