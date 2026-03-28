<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Impresa extends Model
{
    protected $table      = 'imprese';
    protected $primaryKey = 'id_impresa';

    protected $fillable = [
        'ragione_sociale', 'partita_iva', 'referente', 'email', 'cellulare', 'note',
    ];

    protected $hidden = ['password'];

    public function appalti(): HasMany
    {
        return $this->hasMany(Appalto::class, 'id_impresa', 'id_impresa');
    }
}
