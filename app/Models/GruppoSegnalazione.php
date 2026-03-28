<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GruppoSegnalazione extends Model
{
    protected $table      = 'gruppi_segnalazioni';
    protected $primaryKey = 'id_gruppo';
    public    $timestamps = false;

    protected $fillable = ['descrizione', 'icona', 'tipologia', 'cittadini'];

    protected function casts(): array
    {
        return ['cittadini' => 'boolean'];
    }

    public function tipologie(): HasMany
    {
        return $this->hasMany(TipologiaSegnalazione::class, 'id_gruppo', 'id_gruppo');
    }
}
