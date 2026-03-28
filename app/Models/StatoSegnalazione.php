<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatoSegnalazione extends Model
{
    protected $table      = 'db_stato_segnalazioni';
    protected $primaryKey = 'id_stato';
    public    $timestamps = false;

    protected $fillable = [
        'descrizione', 'iniziale', 'in_carico', 'id_gestione', 'sospesa', 'chiusura', 'colore_sfondo',
    ];

    protected function casts(): array
    {
        return [
            'iniziale'    => 'boolean',
            'in_carico'   => 'boolean',
            'id_gestione' => 'boolean',
            'sospesa'     => 'boolean',
            'chiusura'    => 'boolean',
        ];
    }

    /** Restituisce le classi Tailwind per il badge colore. */
    public function badgeClass(): string
    {
        return match($this->colore_sfondo) {
            'primary'   => 'bg-blue-100 text-blue-800',
            'success'   => 'bg-green-100 text-green-800',
            'danger'    => 'bg-red-100 text-red-800',
            'warning'   => 'bg-yellow-100 text-yellow-800',
            'secondary' => 'bg-gray-100 text-gray-700',
            'info'      => 'bg-cyan-100 text-cyan-800',
            default     => 'bg-gray-100 text-gray-600',
        };
    }
}
