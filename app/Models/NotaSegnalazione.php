<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaSegnalazione extends Model
{
    protected $table      = 'note_segnalazioni';
    protected $primaryKey = 'id_nota';

    const CREATED_AT = 'data';
    const UPDATED_AT = null;

    protected $fillable = [
        'id_segnalazione', 'testo', 'id_utente', 'visibile_web', 'visibile_impresa',
    ];

    protected function casts(): array
    {
        return [
            'data'             => 'datetime',
            'visibile_web'     => 'boolean',
            'visibile_impresa' => 'boolean',
        ];
    }

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_utente');
    }

    public function segnalazione(): BelongsTo
    {
        return $this->belongsTo(Segnalazione::class, 'id_segnalazione', 'id_segnalazione');
    }
}
