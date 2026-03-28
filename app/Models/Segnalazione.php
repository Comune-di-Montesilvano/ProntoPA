<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Segnalazione extends Model
{
    protected $table = 'segnalazioni';

    protected $fillable = [
        'id_utente',
        'id_operatore',
        'id_impresa',
        'id_stato',
        'id_tipologia',
        'id_gruppo',
        'id_provenienza',
        'id_appalto',
        'oggetto',
        'descrizione',
        'indirizzo',
        'civico',
        'lat',
        'lng',
        'priorita',
        'note_interne',
        'importo_stimato',
        'importo_liquidato',
        'data_scadenza',
        'data_chiusura',
        'external_id',
    ];

    protected function casts(): array
    {
        return [
            'lat'               => 'float',
            'lng'               => 'float',
            'importo_stimato'   => 'decimal:2',
            'importo_liquidato' => 'decimal:2',
            'data_scadenza'     => 'date',
            'data_chiusura'     => 'date',
        ];
    }

    // ── Relazioni ─────────────────────────────────────────────────────────────

    public function utente()
    {
        return $this->belongsTo(User::class, 'id_utente');
    }

    public function operatore()
    {
        return $this->belongsTo(User::class, 'id_operatore');
    }

    public function stato()
    {
        return $this->belongsTo(StatoSegnalazione::class, 'id_stato', 'id_stato');
    }

    public function note()
    {
        return $this->hasMany(NotaSegnalazione::class, 'id_segnalazione');
    }

    public function storicoStati()
    {
        return $this->hasMany(StoricoStatoSegnalazione::class, 'id_segnalazione')
                    ->orderByDesc('created_at');
    }

    // ── Scope visibilità per ruolo ────────────────────────────────────────────

    public function scopeVisibileA($query, User $user)
    {
        if ($user->hasRole('admin') || $user->isAdmin()) {
            return $query;
        }

        if ($user->hasRole('gestore') || $user->isGestore()) {
            if ($user->isSupervisore()) {
                return $query;
            }
            return $query->where('id_operatore', $user->id);
        }

        if ($user->hasRole('impresa')) {
            return $query->whereHas('appalto', fn ($q) => $q->where('id_impresa', $user->id_impresa));
        }

        // segnalatore: solo proprie
        return $query->where('id_utente', $user->id);
    }
}
