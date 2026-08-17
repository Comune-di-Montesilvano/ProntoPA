<?php

namespace App\Models;

use App\Jobs\ScansionaAllegato;
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
        'stato_scansione',
        'scansionato_at',
    ];

    protected function casts(): array
    {
        return [
            'dimensione'     => 'integer',
            'scansionato_at' => 'datetime',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Unico punto di dispatch: gli allegati vengono creati da più
        // controller (upload diretto, Telegram, magic-link, rapportino di
        // chiusura) — un hook sul model garantisce che nessun path se lo
        // dimentichi.
        static::created(function (self $allegato): void {
            ScansionaAllegato::dispatch($allegato->id_allegato);
        });
    }

    public function isInfetto(): bool
    {
        return $this->stato_scansione === 'infetto';
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
