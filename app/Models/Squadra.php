<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Squadra extends Model
{
    protected $table      = 'squadre';
    protected $primaryKey = 'id_squadra';

    protected $fillable = ['nome', 'id_caposquadra', 'attiva'];

    protected function casts(): array
    {
        return ['attiva' => 'boolean'];
    }

    public function caposquadra(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_caposquadra', 'id');
    }

    public function membri(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'squadra_user', 'id_squadra', 'user_id');
    }
}
