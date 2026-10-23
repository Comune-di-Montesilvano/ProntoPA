<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Impresa extends Model
{
    use HasFactory;
    protected $table      = 'imprese';
    protected $primaryKey = 'id_impresa';

    protected $fillable = [
        'ragione_sociale', 'partita_iva', 'referente', 'email', 'cellulare', 'note', 'attiva',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['attiva' => 'boolean'];
    }

    /** @return HasMany<DelegaImpresa, $this> */
    public function deleghe(): HasMany
    {
        return $this->hasMany(DelegaImpresa::class, 'id_impresa', 'id_impresa');
    }

    public function appalti(): HasMany
    {
        return $this->hasMany(Appalto::class, 'id_impresa', 'id_impresa');
    }
}
