<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Provenienza extends Model
{
    protected $table      = 'provenienze_segnalazioni';
    protected $primaryKey = 'id_provenienza';
    public    $timestamps = false;

    protected $fillable = ['descrizione'];
}
