<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Istituto extends Model
{
    protected $table      = 'istituti';
    protected $primaryKey = 'id_istituto';
    public    $timestamps = false;

    protected $fillable = [
        'descrizione',
        'tipo',
        'tipo_ente',
        'codice_meccanografico',
        'partita_iva',
        'codice_fiscale',
        'dirigente',
        'email',
        'domini_email_istituzionali',
        'fonte_dati',
        'attivo',
        'recapiti',
    ];

    public function plessi(): HasMany
    {
        return $this->hasMany(Plesso::class, 'id_istituto', 'id_istituto');
    }
}
