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
        'RimandataAlGestoreNotification'   => 'Rimandata al comune',
        'RichiestaInformazioniNotification' => 'Richiesta di informazioni',
        'RispostaInformazioniNotification' => 'Risposta alla richiesta',
        'ImpresaAssegnataNotification'     => 'Affidata alla ditta',
        'InattivitaNotification'           => 'Avviso di inattività',
        'NotaDaEsternoNotification'        => 'Nuovo messaggio',
        'NuovaSegnalazioneNotification'    => 'Nuova segnalazione (gestori)',
        'NuovaNotaSegnalazione'            => 'Nuova nota',
        'OperatoreAssegnatoNotification'   => 'Assegnata',
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

    /**
     * Avvisi raggruppati per chi li ha ricevuti: chi ha segnalato, gestori, ditta, operai.
     *
     * @return list<array{a: string, quante: int, ultima: \Illuminate\Support\Carbon, solo_email: bool}>
     */
    public static function riepilogoPer(Segnalazione $segnalazione): array
    {
        $autore = (int) $segnalazione->id_utente_segnalazione;
        $scuola = (bool) User::whereKey($autore)->first()?->isSpid();
        $gruppi = [];
        foreach (self::where('id_segnalazione', $segnalazione->id_segnalazione)->with('utente')->orderBy('id')->get() as $n) {
            $utente = $n->utente;
            $lettura = $utente ? \App\Support\Segnalazioni\LetturaScheda::per($utente) : null;
            $a = match (true) {
                $utente === null => 'Alla ditta',
                (int) $n->id_utente === $autore, $lettura === 'segnalante' => $scuola ? 'Alla scuola' : 'A chi ha segnalato',
                $lettura === 'gestore' => 'Ai gestori',
                $utente->hasRole('impresa') => 'Alla ditta',
                default => 'Agli operai',
            };
            $g = $gruppi[$a] ?? ['a' => $a, 'quante' => 0, 'ultima' => $n->created_at, 'solo_email' => true];
            $g['quante']++;
            $g['ultima'] = $n->created_at;
            $g['solo_email'] = $g['solo_email'] && $n->canale === 'mail';
            $gruppi[$a] = $g;
        }

        return array_values($gruppi);
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_utente');
    }
}
