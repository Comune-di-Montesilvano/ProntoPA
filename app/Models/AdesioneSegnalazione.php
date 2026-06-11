<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdesioneSegnalazione extends Model
{
    protected $table      = 'adesioni_segnalazioni';
    protected $primaryKey = 'id_adesione';

    const UPDATED_AT = null;

    protected $fillable = [
        'id_segnalazione',
        'id_utente',
        'segnalante',
        'telefono',
        'email',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function segnalazione(): BelongsTo
    {
        return $this->belongsTo(Segnalazione::class, 'id_segnalazione', 'id_segnalazione');
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_utente', 'id');
    }
}
