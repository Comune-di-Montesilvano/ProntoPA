<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profilo extends Model
{
    protected $table      = 'profili';
    protected $primaryKey = 'id_profilo';
    public    $timestamps = false;

    protected $fillable = [
        'descrizione', 'limita_istituto', 'id_istituto', 'limita_segnalazioni', 'id_tipologia_segnalazione',
    ];

    protected function casts(): array
    {
        return ['limita_istituto' => 'boolean'];
    }

    public function istituto(): BelongsTo
    {
        return $this->belongsTo(Istituto::class, 'id_istituto', 'id_istituto');
    }
}
