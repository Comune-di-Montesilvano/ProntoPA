<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Delega persona → scuola (istituto intero se id_plesso è NULL). Le righe di
 * una stessa richiesta multi-plesso condividono gruppo_richiesta e token.
 */
class Delega extends Model
{
    public const RICHIESTA = 'richiesta';
    public const ATTIVA = 'attiva';
    public const RIFIUTATA = 'rifiutata';
    public const REVOCATA = 'revocata';
    public const SCADUTA = 'scaduta';

    protected $table = 'deleghe';

    protected $fillable = [
        'user_id', 'codice_fiscale', 'id_istituto', 'id_plesso', 'gruppo_richiesta', 'stato',
        'email_destinatario', 'richiesta_inviata_at', 'decisa_at', 'decisa_via', 'decisa_da',
        'motivo', 'valida_fino_at', 'rinnovo_inviato_at', 'avviso_inviato_at',
        'token_hash', 'token_scadenza_at',
    ];

    protected function casts(): array
    {
        return [
            'richiesta_inviata_at' => 'datetime',
            'decisa_at' => 'datetime',
            'valida_fino_at' => 'datetime',
            'rinnovo_inviato_at' => 'datetime',
            'avviso_inviato_at' => 'datetime',
            'token_scadenza_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Istituto, $this> */
    public function istituto(): BelongsTo
    {
        return $this->belongsTo(Istituto::class, 'id_istituto', 'id_istituto');
    }

    /** @return BelongsTo<Plesso, $this> */
    public function plesso(): BelongsTo
    {
        return $this->belongsTo(Plesso::class, 'id_plesso', 'id_plesso');
    }

    /** @return HasMany<DelegaStorico, $this> */
    public function storico(): HasMany
    {
        return $this->hasMany(DelegaStorico::class, 'id_delega');
    }

    /** Attive e non oltre la scadenza (deleghe:scadenze le chiude ogni mattina). */
    public function scopeAttive(Builder $query): Builder
    {
        return $query->where('stato', self::ATTIVA)
            ->where(fn ($q) => $q->whereNull('valida_fino_at')->orWhere('valida_fino_at', '>=', now()));
    }

    public function descrizioneAmbito(): string
    {
        return $this->id_plesso === null ? "tutto l'istituto" : (string) $this->plesso?->nome;
    }

    public function registra(string $evento, string $via, ?User $da = null, ?string $ip = null): void
    {
        $this->storico()->create([
            'evento' => $evento,
            'via' => $via,
            'id_utente' => $da?->id,
            'ip' => $ip,
        ]);
    }

    /**
     * Plessi coperti dalle deleghe attive dell'utente: deleghe per plesso ∪
     * tutti i plessi degli istituti delegati per intero. Subquery su plessi,
     * riletta a ogni query: revoca e scadenza valgono senza logout.
     */
    public static function plessiCopertiDa(User $user): QueryBuilder
    {
        $attive = fn () => DB::table('deleghe')
            ->where('user_id', $user->id)
            ->where('stato', self::ATTIVA)
            ->where(fn ($q) => $q->whereNull('valida_fino_at')->orWhere('valida_fino_at', '>=', now()));

        return DB::table('plessi')
            ->select('plessi.id_plesso')
            ->where(fn ($q) => $q
                ->whereIn('plessi.id_plesso', $attive()->whereNotNull('id_plesso')->select('id_plesso'))
                ->orWhereIn('plessi.id_istituto', $attive()->whereNull('id_plesso')->select('id_istituto')));
    }
}
