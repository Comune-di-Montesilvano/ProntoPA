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
        'id_istituto',
        'amministratore',
        'gestore_segnalazioni',
        'supervisore_segnalazioni',
        'id_provenienza',
        'id_impresa',
        'telefono',
        'attivo',
        'approval_status',
        'approved_at',
        'approved_by',
        'email_confermata_annualmente_at',
        'prossima_verifica_annuale_at',
        'annual_verification_token_hash',
        'annual_verification_sent_at',
        'annual_verification_due_at',
        'annual_verification_reminder_at',
        'telegram_chat_id',
        'telegram_link_token',
        'telegram_link_expires_at',
        'telegram_verified_at',
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
            'approved_at'             => 'datetime',
            'email_confermata_annualmente_at' => 'datetime',
            'prossima_verifica_annuale_at' => 'datetime',
            'annual_verification_sent_at' => 'datetime',
            'annual_verification_due_at' => 'datetime',
            'annual_verification_reminder_at' => 'datetime',
            'telegram_link_expires_at'=> 'datetime',
            'telegram_verified_at'    => 'datetime',
            'password'                => 'hashed',
            'amministratore'          => 'boolean',
            'gestore_segnalazioni'    => 'boolean',
            'supervisore_segnalazioni'=> 'boolean',
            'attivo'                  => 'boolean',
        ];
    }

    public function routeNotificationForTelegram(): ?string
    {
        return $this->telegram_chat_id;
    }

    // --- Helpers ruolo ---

    public function isAdmin(): bool
    {
        return (bool) $this->amministratore;
    }

    public function isGestore(): bool
    {
        return (bool) $this->gestore_segnalazioni;
    }

    public function isSupervisore(): bool
    {
        return (bool) $this->supervisore_segnalazioni;
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

    public function istituto()
    {
        return $this->belongsTo(Istituto::class, 'id_istituto', 'id_istituto');
    }

    public function squadre(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Squadra::class, 'squadra_user', 'user_id', 'id_squadra');
    }

    public function squadreGuidate(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Squadra::class, 'id_caposquadra', 'id');
    }

    public function isCaposquadra(): bool
    {
        return $this->squadreGuidate()->where('attiva', true)->exists();
    }
}
