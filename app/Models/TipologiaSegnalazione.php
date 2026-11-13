<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipologiaSegnalazione extends Model
{
    protected $table      = 'tipologie_segnalazioni';
    protected $primaryKey = 'id_tipologia_segnalazione';
    public    $timestamps = false;

    protected $fillable = ['descrizione', 'icona', 'attiva'];

    protected function casts(): array
    {
        return ['attiva' => 'boolean'];
    }

    /** Quelle proponibili nel form e nei filtri nuovi (le disattivate restano sulle vecchie). */
    public function scopeAttive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('attiva', true);
    }


    public function segnalazioni(): HasMany
    {
        return $this->hasMany(Segnalazione::class, 'id_tipologia_segnalazione', 'id_tipologia_segnalazione');
    }
}
