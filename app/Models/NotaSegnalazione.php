<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaSegnalazione extends Model
{
    protected $table      = 'note_segnalazioni';
    protected $primaryKey = 'id_nota';

    const CREATED_AT = 'data';
    const UPDATED_AT = null;

    protected $fillable = [
        'id_segnalazione', 'testo', 'id_utente', 'visibile_web', 'visibile_impresa',
    ];

    protected function casts(): array
    {
        return [
            'data'             => 'datetime',
            'visibile_web'     => 'boolean',
            'visibile_impresa' => 'boolean',
        ];
    }

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_utente');
    }

    public function segnalazione(): BelongsTo
    {
        return $this->belongsTo(Segnalazione::class, 'id_segnalazione', 'id_segnalazione');
    }

    /**
     * Note che l'utente può leggere. Il personale del comune le vede tutte;
     * la ditta quelle "visibile impresa" e quelle scritte dalla propria ditta;
     * chiunque altro (segnalanti) quelle "visibile web" e le proprie.
     *
     * @param  Builder<NotaSegnalazione>  $query
     */
    public function scopeVisibiliA(Builder $query, User $user): void
    {
        if ($user->isAdmin() || $user->isGestore() || $user->hasAnyRole(['admin', 'gestore', 'operaio'])) {
            return;
        }

        if ($user->hasRole('impresa')) {
            $query->where(function (Builder $q) use ($user) {
                $q->where('visibile_impresa', true)
                    ->orWhere('id_utente', $user->id);
                if ($user->id_impresa) {
                    $q->orWhereIn('id_utente', User::where('id_impresa', $user->id_impresa)->select('id'));
                }
            });

            return;
        }

        $query->where(fn (Builder $q) => $q->where('visibile_web', true)->orWhere('id_utente', $user->id));
    }
}
