<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, TwoFactorAuthenticatable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'password_legacy',
        'auth_source',
        'ldap_guid',
        'two_factor_metodo',
        'codice_fiscale',
        'oidc_subject',
        'bloccato_at',
        'motivo_blocco',
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
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'last_login'              => 'datetime',
            'approved_at'             => 'datetime',
            'bloccato_at'             => 'datetime',
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

    public function isLocale(): bool
    {
        return ($this->auth_source ?? 'locale') === 'locale';
    }

    public function isSpid(): bool
    {
        return $this->auth_source === 'spid';
    }

    /** Secondi minimi tra due invii del link di conferma email. */
    public const ATTESA_REINVIO_VERIFICA = 60;

    public function sendEmailVerificationNotification(): void
    {
        parent::sendEmailVerificationNotification();
        \Illuminate\Support\Facades\Cache::put('verifica-email-inviata:'.$this->id, now()->timestamp, self::ATTESA_REINVIO_VERIFICA);
    }

    /** Secondi che mancano prima di poter chiedere un nuovo link di conferma (0 = subito). */
    public function attesaReinvioVerifica(): int
    {
        $inviata = (int) \Illuminate\Support\Facades\Cache::get('verifica-email-inviata:'.$this->id, 0);

        return max(0, $inviata + self::ATTESA_REINVIO_VERIFICA - now()->timestamp);
    }

    /** Scuola (SPID o segnalatore "Direzioni didattiche"): plesso e dettagli obbligatori, niente mappa. */
    public function segnalaComeScuola(): bool
    {
        return $this->isSpid()
            || ($this->hasRole('segnalatore') && (int) $this->id_provenienza === \App\Services\Auth\SpidLoginService::PROVENIENZA_SCUOLE);
    }

    /** I segnalatori segnalano sempre con la propria provenienza; gestori e admin la scelgono. */
    public function puoScegliereProvenienza(): bool
    {
        return blank($this->id_provenienza) || (! $this->isSpid() && ! $this->hasRole('segnalatore'));
    }

    public function isBloccato(): bool
    {
        return $this->bloccato_at !== null;
    }

    /**
     * Secondo fattore richiesto al login: TOTP confermato, solo per account
     * locali (per ldap/spid l'autenticazione forte è di AD/SPID). Il codice
     * via email per le ditte è stato rimosso in v0.8.19 (ditte via SPID).
     */
    public function metodoSecondoFattore(): ?string
    {
        if (! $this->isLocale()) {
            return null;
        }

        return $this->hasEnabledTwoFactorAuthentication() ? 'totp' : null;
    }

    // --- Relazioni ---

    public function profilo()
    {
        return $this->belongsTo(Profilo::class, 'id_profilo', 'id_profilo');
    }

    public function impresa()
    {
        return $this->belongsTo(Impresa::class, 'id_impresa', 'id_impresa');
    }

    public function provenienza()
    {
        return $this->belongsTo(Provenienza::class, 'id_provenienza', 'id_provenienza');
    }

    public function istituto()
    {
        return $this->belongsTo(Istituto::class, 'id_istituto', 'id_istituto');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Delega, $this> */
    public function deleghe(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Delega::class);
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
