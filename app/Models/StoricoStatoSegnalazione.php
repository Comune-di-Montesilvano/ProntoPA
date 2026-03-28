<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoricoStatoSegnalazione extends Model
{
    protected $table      = 'stati_segnalazioni';
    protected $primaryKey = 'id';

    const CREATED_AT = 'data_registrazione';
    const UPDATED_AT = null;

    protected $fillable = [
        'id_segnalazione', 'id_stato_segnalazione', 'id_utente', 'id_utente_collegato', 'id_appalto',
    ];

    protected function casts(): array
    {
        return ['data_registrazione' => 'datetime'];
    }

    public function stato(): BelongsTo
    {
        return $this->belongsTo(StatoSegnalazione::class, 'id_stato_segnalazione', 'id_stato');
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_utente');
    }
}
