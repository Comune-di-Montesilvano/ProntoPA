<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plesso extends Model
{
    protected $table      = 'plessi';
    protected $primaryKey = 'id_plesso';
    public    $timestamps = false;

    protected $fillable = [
        'id_istituto', 'nome', 'codice_meccanografico', 'indirizzo', 'referente', 'email', 'recapiti',
    ];

    public function istituto(): BelongsTo
    {
        return $this->belongsTo(Istituto::class, 'id_istituto', 'id_istituto');
    }
}
