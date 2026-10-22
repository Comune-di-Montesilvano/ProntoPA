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

    /** @return BelongsTo<Istituto, $this> */
    public function istituto(): BelongsTo
    {
        return $this->belongsTo(Istituto::class, 'id_istituto', 'id_istituto');
    }

    public function isMiur(): bool
    {
        return $this->fonte_dati === 'miur';
    }

    /** Ordine di scuola dal codice meccanografico MIUR (3ª-4ª lettera), se riconoscibile. */
    public function ordineScuola(): ?string
    {
        return match (strtoupper(substr((string) $this->codice_meccanografico, 2, 2))) {
            'AA' => 'infanzia',
            'EE' => 'primaria',
            'MM' => 'secondaria I grado',
            default => null,
        };
    }

    /**
     * Testo per le select: nomi simili (doppioni legacy/MIUR, più plessi nella
     * stessa via) si distinguono solo con ordine, codice e indirizzo.
     */
    public function etichetta(): string
    {
        return $this->nome.' — '.$this->dettagli();
    }

    /** "primaria · PEEE037078 · VIA LAZIO, MONTESILVANO" */
    public function dettagli(): string
    {
        return implode(' · ', array_filter([
            $this->ordineScuola(),
            $this->codice_meccanografico ?: 'senza codice',
            $this->indirizzo,
        ]));
    }

    /** Abbinamento per codice meccanografico, indipendente da maiuscole e spazi. */
    public function scopeConCodice(Builder $query, string $codice): Builder
    {
        return $query->whereRaw('UPPER(TRIM(codice_meccanografico)) = ?', [strtoupper(trim($codice))]);
    }
}
