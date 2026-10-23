<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona fisica (codice fiscale) che gestisce una ditta, delegata da un
 * operatore del comune. Agganciata all'utente al primo accesso SPID/CIE.
 */
class DelegaImpresa extends Model
{
    public const ATTIVA = 'attiva';
    public const REVOCATA = 'revocata';

    protected $table = 'deleghe_imprese';

    protected $fillable = [
        'codice_fiscale', 'user_id', 'id_impresa', 'stato', 'motivo',
        'creata_da', 'revocata_da', 'revocata_at',
    ];

    protected function casts(): array
    {
        return ['revocata_at' => 'datetime'];
    }

    public function scopeAttive(Builder $query): Builder
    {
        return $query->where('stato', self::ATTIVA);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Impresa, $this> */
    public function impresa(): BelongsTo
    {
        return $this->belongsTo(Impresa::class, 'id_impresa', 'id_impresa');
    }

    /** @return BelongsTo<User, $this> */
    public function creataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creata_da');
    }

    /** @return BelongsTo<User, $this> */
    public function revocataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revocata_da');
    }
}
