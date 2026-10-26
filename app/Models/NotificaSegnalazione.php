<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una notifica partita (email, Telegram) che riguarda una segnalazione. */
class NotificaSegnalazione extends Model
{
    protected $table = 'notifiche_segnalazioni';

    public const UPDATED_AT = null;

    protected $fillable = ['id_segnalazione', 'tipo', 'canale', 'id_utente', 'destinatario'];

    /** Classi senza etichetta compaiono col nome tecnico: aggiungerle qui. */
    private const ETICHETTE = [
        'ArchiviataInattivitaNotification' => 'Archiviata per inattività',
        'AccorpataNotification'            => 'Accorpata',
        'FineLavoriNotification'           => 'Fine lavori',
        'ImpresaAssegnataNotification'     => 'Affidata alla ditta',
        'InattivitaNotification'           => 'Avviso di inattività',
        'NotaDaEsternoNotification'        => 'Nuovo messaggio',
        'NuovaNotaSegnalazione'            => 'Nuova nota',
        'OperatoreAssegnatoNotification'   => 'Assegnata a operatore',
        'RiepilogoInattivitaNotification'  => 'Riepilogo inattività (supervisori)',
        'SegnalazioneChiusaNotification'   => 'Chiusa',
        'SegnalazioneStatoCambiato'        => 'Cambio di stato',
        'SlaViolazioneNotification'        => 'Tempo superato',
        'SlaWarningNotification'           => 'A rischio',
        'SollecitoNotification'            => 'Sollecito',
        'SquadraAssegnataNotification'     => 'Assegnata alla squadra',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'id_utente' => 'integer'];
    }

    public function etichetta(): string
    {
        return self::ETICHETTE[$this->tipo] ?? $this->tipo;
    }

    public function canaleLeggibile(): string
    {
        return match ($this->canale) {
            'mail'     => 'email',
            'telegram' => 'Telegram',
            default    => $this->canale,
        };
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_utente');
    }
}
