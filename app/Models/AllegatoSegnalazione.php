<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllegatoSegnalazione extends Model
{
    use HasFactory;

    protected $table = 'allegati_segnalazioni';
    protected $primaryKey = 'id_allegato';

    protected $fillable = [
        'id_segnalazione',
        'percorso',
        'tipo',
        'nome_originale',
        'dimensione',
        'id_utente_creazione',
        'fase',
    ];

    protected function casts(): array
    {
        return [
            'dimensione' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function segnalazione(): BelongsTo
    {
        return $this->belongsTo(Segnalazione::class, 'id_segnalazione', 'id_segnalazione');
    }

    public function utenteCreazione(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_utente_creazione', 'id');
    }
}
