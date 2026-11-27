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
    public const ETICHETTE = [
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
            'mail'               => 'email',
            'telegram'           => 'Telegram',
            'mail:riepilogo'     => 'email (nel riepilogo)',
            'telegram:riepilogo' => 'Telegram (nel riepilogo)',
            default              => $this->canale,
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
            $g['solo_email'] = $g['solo_email'] && str_starts_with((string) $n->canale, 'mail');
            $gruppi[$a] = $g;
        }

        return array_values($gruppi);
    }

    /**
     * Avvisi tenuti da parte per il riepilogo, con quando partono. Uno per tipo e ritmo: più destinatari
     * dello stesso avviso si contano.
     *
     * @return list<array{etichetta: string, destinatari: int, quando: string}>
     */
    public static function inAttesaPer(Segnalazione $segnalazione, ?\Carbon\CarbonInterface $adesso = null): array
    {
        $adesso = ($adesso ?? now())->copy()->setTimezone((string) config('app.timezone'));
        $giri = \App\Support\Avvisi\ProssimoGiro::daImpostazioni();
        $gruppi = [];
        $righe = AvvisoInAttesa::query()->inAttesa()
            ->where('id_segnalazione', $segnalazione->id_segnalazione)
            ->orderBy('id')->get(['id', 'tipo', 'ritmo', 'destinatario_tipo', 'id_utente', 'email', 'notifica']);
        foreach ($righe as $r) {
            if (! self::partiraAncora($r)) {
                continue; // il giro la scarterebbe: non dire «in attesa»
            }
            $chiave = $r->tipo.'|'.$r->ritmo;
            $gruppi[$chiave] ??= ['tipo' => $r->tipo, 'ritmo' => $r->ritmo, 'chi' => []];
            $gruppi[$chiave]['chi'][$r->chiaveDestinatario()] = true;
        }

        $elenco = [];
        foreach ($gruppi as $g) {
            $elenco[] = [
                'etichetta'   => self::ETICHETTE[$g['tipo']] ?? $g['tipo'],
                'destinatari' => count($g['chi']),
                'quando'      => self::quandoParte($g['ritmo'], $giri->perRitmo($g['ritmo'], $adesso), $adesso),
            ];
        }

        return $elenco;
    }

    /** Stessa prova del giro (`ComponiRiepilogo`): destinatario attivo e avviso ancora valido. */
    private static function partiraAncora(AvvisoInAttesa $riga): bool
    {
        $notifica = $riga->notifica();
        $chi = $riga->notificabile();
        if (! $notifica instanceof \App\Notifications\Riepilogo\Raggruppabile || $chi === null) {
            return false;
        }
        try {
            return $notifica->ancoraValida($chi);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function quandoParte(string $ritmo, ?\Carbon\CarbonInterface $giro, \Carbon\CarbonInterface $adesso): string
    {
        if ($giro === null) {
            return 'in attesa del prossimo riepilogo';
        }
        $giro = $giro->copy()->locale('it');
        $domani = $adesso->copy()->addDay()->isSameDay($giro);

        if ($ritmo === \App\Support\Avvisi\Categorie::RITMO_ORA) {
            $giorno = match (true) {
                $giro->isSameDay($adesso) => '',
                $domani                   => ' di domani',
                default                   => ' di '.$giro->isoFormat('ddd D/M'),
            };

            return 'in attesa, parte con il riepilogo delle '.$giro->format('H:i').$giorno;
        }

        return 'in attesa, parte con il riepilogo '.match (true) {
            $giro->isSameDay($adesso) => 'di stamattina',
            $domani                   => 'di domattina',
            default                   => 'del '.$giro->isoFormat('dddd D/M'),
        };
    }

    /** @return BelongsTo<User, $this> */
    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_utente');
    }
}
