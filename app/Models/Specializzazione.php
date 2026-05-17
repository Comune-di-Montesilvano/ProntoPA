<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Specializzazione extends Model
{
    protected $table      = 'db_specializzazioni';
    protected $primaryKey = 'id_specializzazione';
    public    $timestamps = false;

    protected $fillable = ['descrizione'];

    public function segnalazioni(): HasMany
    {
        return $this->hasMany(Segnalazione::class, 'id_specializzazione', 'id_specializzazione');
    }
}
