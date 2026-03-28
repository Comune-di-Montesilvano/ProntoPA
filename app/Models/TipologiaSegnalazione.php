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

    protected $fillable = ['descrizione', 'icona', 'id_gruppo'];

    public function gruppo(): BelongsTo
    {
        return $this->belongsTo(GruppoSegnalazione::class, 'id_gruppo', 'id_gruppo');
    }

    public function segnalazioni(): HasMany
    {
        return $this->hasMany(Segnalazione::class, 'id_tipologia_segnalazione', 'id_tipologia_segnalazione');
    }
}
