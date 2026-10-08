<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Audit di ogni transizione di una delega (stesso pattern di stati_segnalazioni). */
class DelegaStorico extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'deleghe_storico';

    protected $fillable = ['id_delega', 'evento', 'via', 'id_utente', 'ip'];
}
