<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Azione extends Model
{
    protected $table      = 'db_azioni';
    protected $primaryKey = 'id_azione';
    public    $timestamps = false;

    protected $fillable = [
        'descrizione', 'id_stato_segnalazione', 'competenza_azione',
        'colore', 'flag_appalto', 'flag_operatore', 'flag_notifica', 'ordine', 'parametri_filtro',
    ];

    protected function casts(): array
    {
        return [
            'flag_appalto'     => 'boolean',
            'flag_operatore'   => 'boolean',
            'flag_notifica'    => 'boolean',
            'parametri_filtro' => 'array',
        ];
    }

    public function statoTarget(): BelongsTo
    {
        return $this->belongsTo(StatoSegnalazione::class, 'id_stato_segnalazione', 'id_stato');
    }

    /** Classi Tailwind per il pulsante azione. */
    public function btnClass(): string
    {
        return match($this->colore) {
            'primary'   => 'bg-blue-600 hover:bg-blue-700 text-white',
            'success'   => 'bg-green-600 hover:bg-green-700 text-white',
            'danger'    => 'bg-red-600 hover:bg-red-700 text-white',
            'warning'   => 'bg-yellow-500 hover:bg-yellow-600 text-white',
            'secondary' => 'bg-gray-500 hover:bg-gray-600 text-white',
            default     => 'bg-blue-600 hover:bg-blue-700 text-white',
        };
    }
}
