<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appalto extends Model
{
    protected $table      = 'appalti';
    protected $primaryKey = 'id_appalto';

    protected $fillable = [
        'id_gruppo', 'descrizione', 'id_impresa', 'CIG', 'importo_appalto', 'valido',
    ];

    protected function casts(): array
    {
        return [
            'importo_appalto' => 'decimal:2',
            'valido'          => 'boolean',
        ];
    }

    public function impresa(): BelongsTo
    {
        return $this->belongsTo(Impresa::class, 'id_impresa', 'id_impresa');
    }

    public function gruppo(): BelongsTo
    {
        return $this->belongsTo(GruppoSegnalazione::class, 'id_gruppo', 'id_gruppo');
    }
}
