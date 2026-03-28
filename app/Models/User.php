<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'password_legacy',
        'id_profilo',
        'amministratore',
        'gestore_segnalazioni',
        'supervisore_segnalazioni',
        'id_provenienza',
        'last_login',
    ];

    protected $hidden = [
        'password',
        'password_legacy',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'last_login'              => 'datetime',
            'password'                => 'hashed',
            'amministratore'          => 'boolean',
            'gestore_segnalazioni'    => 'boolean',
            'supervisore_segnalazioni'=> 'boolean',
        ];
    }

    // --- Helpers ruolo ---

    public function isAdmin(): bool
    {
        return $this->amministratore;
    }

    public function isGestore(): bool
    {
        return $this->gestore_segnalazioni;
    }

    public function isSupervisore(): bool
    {
        return $this->supervisore_segnalazioni;
    }

    // --- Relazioni ---

    public function profilo()
    {
        return $this->belongsTo(Profilo::class, 'id_profilo', 'id_profilo');
    }

    public function provenienza()
    {
        return $this->belongsTo(Provenienza::class, 'id_provenienza', 'id_provenienza');
    }
}
