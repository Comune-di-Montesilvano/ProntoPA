<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Squadra;

class Segnalazione extends Model
{
    use HasFactory;

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
        'data_scadenza_sla',
        'sla_violato',
        'sla_warning_inviato',
        'external_id',
        'segnalazione_urgente',
        'livello_priorita',
        'id_specializzazione',
        'ubicazione_tipo',
        'id_squadra_assegnata',
    ];

    protected function casts(): array
    {
        return [
            'data_segnalazione'      => 'datetime',
            'data_chiusura'          => 'datetime',
            'latitudine'             => 'float',
            'longitudine'            => 'float',
            'flag_riservata'         => 'boolean',
            'flag_pubblicata'        => 'boolean',
            'flag_evidenza'          => 'boolean',
            'importo_preventivo'     => 'decimal:2',
            'importo_liquidato'      => 'decimal:2',
            'id_utente_segnalazione' => 'integer',
            'id_operatore_assegnato' => 'integer',
            'segnalazione_urgente'   => 'boolean',
            'livello_priorita'       => 'integer',
            'ubicazione_tipo'        => 'integer',
            'data_scadenza_sla'      => 'datetime',
            'sla_violato'            => 'boolean',
            'sla_warning_inviato'    => 'boolean',
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

    public function specializzazione(): BelongsTo
    {
        return $this->belongsTo(Specializzazione::class, 'id_specializzazione', 'id_specializzazione');
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

    public function allegati(): HasMany
    {
        return $this->hasMany(AllegatoSegnalazione::class, 'id_segnalazione', 'id_segnalazione')
            ->orderByDesc('created_at');
    }

    public function adesioni(): HasMany
    {
        return $this->hasMany(AdesioneSegnalazione::class, 'id_segnalazione', 'id_segnalazione')
            ->orderByDesc('created_at');
    }

    public function squadraAssegnata(): BelongsTo
    {
        return $this->belongsTo(Squadra::class, 'id_squadra_assegnata', 'id_squadra');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isChiusa(): bool
    {
        return $this->data_chiusura !== null;
    }

    public function getLabelPrioritaAttribute(): string
    {
        return match((int) $this->livello_priorita) {
            1 => 'Bassa',
            3 => 'Alta',
            4 => 'Critica',
            default => 'Media',
        };
    }

    public function getLabelUbicazioneAttribute(): string
    {
        return match((int) $this->ubicazione_tipo) {
            1 => 'Interno edificio',
            2 => 'Esterno',
            3 => 'Impianto',
            4 => 'Area verde',
            default => 'N/D',
        };
    }

    public function getBadgePrioritaClassAttribute(): string
    {
        return match((int) $this->livello_priorita) {
            1 => 'bg-gray-100 text-gray-600',
            3 => 'bg-orange-100 text-orange-700',
            4 => 'bg-red-100 text-red-700',
            default => 'bg-blue-100 text-blue-700',
        };
    }

    // ── Scope visibilità per ruolo ────────────────────────────────────────────

    public function scopeVisibileA($query, User $user)
    {
        if ($user->hasRole('admin') || $user->isAdmin()) {
            return $query;
        }

        if ($user->hasRole('operaio')) {
            $squadreIds        = $user->squadre()->pluck('squadre.id_squadra');
            $squadreGuidateIds = $user->squadreGuidate()->pluck('id_squadra');

            return $query->where(function ($q) use ($user, $squadreIds, $squadreGuidateIds) {
                $q->where('id_operatore_assegnato', $user->id);

                if ($squadreGuidateIds->isNotEmpty()) {
                    // Caposquadra: tutto ciò che è della squadra
                    $q->orWhereIn('id_squadra_assegnata', $squadreGuidateIds);
                }

                if ($squadreIds->isNotEmpty()) {
                    // Membro: lavori di squadra non ancora smistati a un singolo
                    $q->orWhere(function ($qq) use ($squadreIds) {
                        $qq->whereIn('id_squadra_assegnata', $squadreIds)
                           ->where(fn ($z) => $z->where('id_operatore_assegnato', 0)
                                                 ->orWhereNull('id_operatore_assegnato'));
                    });
                }
            });
        }

        if ($user->hasRole('gestore') || $user->isGestore()) {
            if ($user->isSupervisore()) {
                return $query;
            }
            return $query->where(function ($q) use ($user) {
                $q->where('id_operatore_assegnato', $user->id)
                  ->orWhere('id_utente_segnalazione', $user->id);
            });
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

    public function scopePubbliche($query)
    {
        return $query
            ->where('flag_pubblicata', true)
            ->where('flag_riservata', false);
    }

    public function scopeCritica($query)
    {
        return $query->where('livello_priorita', 4);
    }

    public function scopeUrgente($query)
    {
        return $query->where('segnalazione_urgente', true);
    }

    public function scopeSlaArischio($query)
    {
        return $query->whereNotNull('data_scadenza_sla')
                     ->where('sla_violato', false)
                     ->whereRaw('data_scadenza_sla > NOW()');
    }

    public function scopeSlaViolato($query)
    {
        return $query->where('sla_violato', true);
    }
}
