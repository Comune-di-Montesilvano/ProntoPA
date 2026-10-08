<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plesso extends Model
{
    protected $table      = 'plessi';
    protected $primaryKey = 'id_plesso';
    public    $timestamps = false;

    protected $fillable = [
        'id_istituto', 'nome', 'codice_meccanografico', 'indirizzo', 'referente', 'email', 'recapiti', 'fonte_dati',
    ];

    public function istituto(): BelongsTo
    {
        return $this->belongsTo(Istituto::class, 'id_istituto', 'id_istituto');
    }

    public function isMiur(): bool
    {
        return $this->fonte_dati === 'miur';
    }

    /** Abbinamento per codice meccanografico, indipendente da maiuscole e spazi. */
    public function scopeConCodice(Builder $query, string $codice): Builder
    {
        return $query->whereRaw('UPPER(TRIM(codice_meccanografico)) = ?', [strtoupper(trim($codice))]);
    }
}
