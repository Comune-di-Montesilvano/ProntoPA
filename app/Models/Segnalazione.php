<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Segnalazione extends Model
{
    protected $table      = 'segnalazioni';
    protected $primaryKey = 'id_segnalazione';

    // data_segnalazione funge da created_at; no updated_at
    const CREATED_AT = 'data_segnalazione';
    const UPDATED_AT = null;

    protected $fillable = [
        'id_tipologia_segnalazione',
        'id_plesso',
        'id_utente_segnalazione',
        'id_cittadino_segnalazione',
        'id_stradario',
        'id_area',
        'id_immobile',
        'latitudine',
        'longitudine',
        'zoom',
        'testo_segnalazione',
        'flag_riservata',
        'flag_pubblicata',
        'flag_evidenza',
        'id_stato_segnalazione',
        'id_provenienza',
        'id_appalto',
        'id_operatore_assegnato',
        'segnalante',
        'email',
        'telefono',
        'importo_preventivo',
        'importo_liquidato',
        'data_chiusura',
        'external_id',
    ];

    protected function casts(): array
    {
        return [
            'data_segnalazione'  => 'datetime',
            'data_chiusura'      => 'datetime',
            'latitudine'         => 'float',
            'longitudine'        => 'float',
            'flag_riservata'     => 'boolean',
            'flag_pubblicata'    => 'boolean',
            'flag_evidenza'      => 'boolean',
            'importo_preventivo' => 'decimal:2',
            'importo_liquidato'  => 'decimal:2',
        ];
    }

    // ── Relazioni ─────────────────────────────────────────────────────────────

    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_utente_segnalazione');
    }

    public function operatore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_operatore_assegnato');
    }

    public function stato(): BelongsTo
    {
        return $this->belongsTo(StatoSegnalazione::class, 'id_stato_segnalazione', 'id_stato');
    }

    public function tipologia(): BelongsTo
    {
        return $this->belongsTo(TipologiaSegnalazione::class, 'id_tipologia_segnalazione', 'id_tipologia_segnalazione');
    }

    public function provenienza(): BelongsTo
    {
        return $this->belongsTo(Provenienza::class, 'id_provenienza', 'id_provenienza');
    }

    public function plesso(): BelongsTo
    {
        return $this->belongsTo(Plesso::class, 'id_plesso', 'id_plesso');
    }

    public function appalto(): BelongsTo
    {
        return $this->belongsTo(Appalto::class, 'id_appalto', 'id_appalto');
    }

    public function note(): HasMany
    {
        return $this->hasMany(NotaSegnalazione::class, 'id_segnalazione', 'id_segnalazione')
                    ->orderByDesc('data');
    }

    public function storicoStati(): HasMany
    {
        return $this->hasMany(StoricoStatoSegnalazione::class, 'id_segnalazione', 'id_segnalazione')
                    ->orderByDesc('data_registrazione');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isChiusa(): bool
    {
        return $this->data_chiusura !== null;
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
            return $query->where('id_operatore_assegnato', $user->id);
        }

        if ($user->hasRole('impresa') && $user->id_impresa) {
            return $query->whereHas('appalto', fn ($q) => $q->where('id_impresa', $user->id_impresa));
        }

        // Segnalatore scuola: vede tutte le segnalazioni dei plessi del suo istituto
        $profilo = $user->profilo;
        if ($profilo && $profilo->limita_istituto && $profilo->id_istituto) {
            $plessoIds = Plesso::where('id_istituto', $profilo->id_istituto)
                               ->pluck('id_plesso');
            return $query->whereIn('id_plesso', $plessoIds);
        }

        // Segnalatore generico (URP, interni): solo proprie
        return $query->where('id_utente_segnalazione', $user->id);
    }

    public function scopeAperte($query)
    {
        return $query->whereNull('data_chiusura');
    }

    public function scopeInEvidenza($query)
    {
        return $query->where('flag_evidenza', true)->whereNull('data_chiusura');
    }
}
