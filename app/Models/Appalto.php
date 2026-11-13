<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appalto extends Model
{
    protected $table      = 'appalti';
    protected $primaryKey = 'id_appalto';

    protected $fillable = [
        'id_gruppo', 'descrizione', 'id_impresa', 'CIG', 'importo_appalto', 'valido', 'tutte_tipologie',
    ];

    protected function casts(): array
    {
        return [
            'importo_appalto' => 'decimal:2',
            'valido'          => 'boolean',
            'tutte_tipologie' => 'boolean',
        ];
    }

    /** @return BelongsTo<Impresa, $this> */
    public function impresa(): BelongsTo
    {
        return $this->belongsTo(Impresa::class, 'id_impresa', 'id_impresa');
    }

    /** Tipologie che l'appalto copre (oltre al flag "tutte"). */
    public function tipologie(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(TipologiaSegnalazione::class, 'appalti_tipologie', 'id_appalto', 'id_tipologia_segnalazione');
    }

    public function copreTipologia(int $idTipologia): bool
    {
        if ($this->tutte_tipologie) {
            return true;
        }

        return $this->relationLoaded('tipologie')
            ? $this->tipologie->contains('id_tipologia_segnalazione', $idTipologia)
            : $this->tipologie()->whereKey($idTipologia)->exists();
    }

    public function gruppo(): BelongsTo
    {
        return $this->belongsTo(GruppoSegnalazione::class, 'id_gruppo', 'id_gruppo');
    }
}
