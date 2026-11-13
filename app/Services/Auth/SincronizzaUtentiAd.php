<?php

namespace App\Services\Auth;

use App\Models\Impostazione;
use App\Models\User;
use App\Services\Directory\Directory;
use App\Services\Directory\DirectoryIdentity;
use App\Services\Directory\DirectoryNonDisponibile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AD comanda: allinea gli utenti di dominio ai membri dei gruppi PRONTOPA_*.
 * Crea/aggiorna/riattiva chi c'è, disattiva chi è uscito o è disabilitato.
 * Mai disattivazioni di massa per un AD irraggiungibile o mal configurato.
 */
final class SincronizzaUtentiAd
{
    private const CHIAVE_ESITO = 'ldap_sync_esito';

    /** Sotto questo numero di utenti ldap attivi la soglia non scatta. */
    private const MINIMO_PER_SOGLIA = 5;

    public function __construct(
        private readonly Directory $directory,
        private readonly MappaGruppiLdap $mappa,
        private readonly AllineaUtenteAd $allinea,
    ) {}

    /** Segnale "giro in esecuzione" leggibile senza prendere il lock (pagina admin). */
    private const CHIAVE_ATTIVO = 'ldap-sync-attivo';

    /**
     * @param  bool  $forza  ignora la soglia del 50% di disattivazioni
     * @param  bool  $prova  calcola senza scrivere né salvare l'esito
     * @param  int|null  $confermati  con $forza: disattivazioni viste e confermate dall'admin; se nel frattempo sono di più, il giro si blocca di nuovo
     */
    public function esegui(bool $forza = false, bool $prova = false, ?int $confermati = null): EsitoSyncAd
    {
        if (! $this->directory->syncConfigurata()) {
            return EsitoSyncAd::fallita('Sincronizzazione non configurata (LDAP_SYNC_USERNAME / LDAP_SYNC_PASSWORD).');
        }

        $lock = Cache::lock('ldap-sync', 600);
        if (! $lock->get()) {
            // Non un fallimento: l'esito del giro in corso non va sovrascritto né gli admin avvisati.
            return new EsitoSyncAd(EsitoSyncAd::IN_CORSO, now(), motivo: 'Sincronizzazione già in corso.');
        }
        Cache::put(self::CHIAVE_ATTIVO, true, 600);

        try {
            $esito = $this->giro($forza, $prova, $confermati);
        } catch (\Throwable $e) {
            // Mai un fallimento silenzioso: esito salvato e admin avvisati (transazione già annullata).
            report($e);
            $esito = EsitoSyncAd::fallita('Errore imprevisto: '.$e->getMessage().' Nessun utente modificato.');
        } finally {
            Cache::forget(self::CHIAVE_ATTIVO);
            $lock->release();
        }

        if (! $prova) {
            Impostazione::set(self::CHIAVE_ESITO, json_encode($esito->toArray()));
            if (! $esito->ok()) {
                Log::error('Sincronizzazione AD '.$esito->stato.': '.$esito->motivo);
            } elseif ($esito->errori > 0) {
                Log::error('Sincronizzazione AD: '.$esito->errori.' utenti non allineati per errore, vedi eccezioni riportate.');
            }
        }

        return $esito;
    }

    public function inCorso(): bool
    {
        return Cache::has(self::CHIAVE_ATTIVO);
    }

    public function ultimoEsito(): ?EsitoSyncAd
    {
        $json = Impostazione::get(self::CHIAVE_ESITO);
        $dati = is_string($json) ? json_decode($json, true) : null;

        return is_array($dati) ? EsitoSyncAd::fromArray($dati) : null;
    }

    private function giro(bool $forza, bool $prova, ?int $confermati): EsitoSyncAd
    {
        try {
            $membri = $this->directory->membriGruppi();
        } catch (DirectoryNonDisponibile $e) {
            return EsitoSyncAd::fallita($e->getMessage());
        }

        // Gruppo configurato ma assente in AD (es. default PRONTOPA_URP mai creato):
        // innocuo se nessuno ha quel ruolo, altrimenti i suoi membri verrebbero disattivati.
        $mancanti = $this->directory->gruppiMancantiUltimaLettura();
        $mancantiConUtenti = array_values(array_filter(
            $mancanti,
            fn (string $g) => $this->utentiDelGruppo($g, $mancanti) > 0,
        ));
        if ($mancantiConUtenti !== []) {
            return EsitoSyncAd::fallita(sprintf(
                'Gruppi non trovati in Active Directory: %s, ma in ProntoPA ci sono utenti attivi con quel ruolo. Controlla nome e posizione del gruppo (sotto LDAP_BASE_DN). Nessun utente modificato.',
                implode(', ', $mancantiConUtenti)));
        }

        if ($membri === []) {
            return EsitoSyncAd::fallita('0 membri trovati nei gruppi: controlla LDAP_BASE_DN e i nomi dei gruppi. Nessun utente disattivato.');
        }

        /** @var list<array{0: DirectoryIdentity, 1: RuoloLdap}> $daAllineare */
        $daAllineare = [];
        /** @var array<string, true> $restano guid che non vanno disattivati */
        $restano = [];
        $senzaEmail = 0;

        foreach ($membri as $m) {
            $ruolo = $this->mappa->risolvi($m->groups);
            if (! $m->abilitato || $ruolo === null) {
                continue;
            }
            $restano[$m->guid] = true;

            if (blank($m->email)) {
                $senzaEmail++;

                continue;
            }
            $daAllineare[] = [$m, $ruolo];
        }

        $attiviLdap = User::where('auth_source', 'ldap')->where('attivo', true);
        $daDisattivare = (clone $attiviLdap)->get(['id', 'ldap_guid'])
            ->reject(fn (User $u) => isset($restano[(string) $u->ldap_guid]))
            ->pluck('id');
        $totaleAttivi = $attiviLdap->count();
        $oltreSoglia = $totaleAttivi >= self::MINIMO_PER_SOGLIA && $daDisattivare->count() * 2 > $totaleAttivi;

        // L'admin ha confermato un numero preciso: se AD nel frattempo ne toglie altri, si richiede conferma.
        if ($forza && $oltreSoglia && $confermati !== null && $daDisattivare->count() > $confermati) {
            return new EsitoSyncAd(EsitoSyncAd::BLOCCATA, now(),
                daDisattivare: $daDisattivare->count(),
                senzaEmail: $senzaEmail,
                motivo: sprintf('Avevi confermato %d disattivazioni, ora il giro ne farebbe %d su %d attivi: nessuna modifica. Controlla i gruppi AD e conferma di nuovo.',
                    $confermati, $daDisattivare->count(), $totaleAttivi));
        }

        if (! $forza && $oltreSoglia) {
            return new EsitoSyncAd(EsitoSyncAd::BLOCCATA, now(),
                daDisattivare: $daDisattivare->count(),
                senzaEmail: $senzaEmail,
                motivo: sprintf('Il giro disattiverebbe %d utenti di dominio su %d attivi: nessuna modifica. Controlla i gruppi AD o sincronizza comunque.',
                    $daDisattivare->count(), $totaleAttivi));
        }

        if ($prova) {
            return $this->previsione($daAllineare, $daDisattivare->count(), $senzaEmail);
        }

        return DB::transaction(function () use ($daAllineare, $daDisattivare, $senzaEmail) {
            $creati = $aggiornati = $riattivati = $occupati = $errori = 0;

            foreach ($daAllineare as [$m, $ruolo]) {
                $prima = User::where('ldap_guid', $m->guid)->first(['id', 'attivo']);
                try {
                    // applica() è una transazione annidata (savepoint): un errore annulla solo questo utente.
                    $this->allinea->applica($m, $ruolo, login: false);
                } catch (UsernameOccupato) {
                    $occupati++;

                    continue;
                } catch (\Throwable $e) {
                    report($e);
                    $errori++;

                    continue;
                }
                match (true) {
                    $prima === null => $creati++,
                    ! $prima->attivo => $riattivati++,
                    default => $aggiornati++,
                };
            }

            // Ruoli e tipologie restano: se rientra nel gruppo li ritrova ricalcolati.
            User::whereIn('id', $daDisattivare)->update(['attivo' => false]);

            return new EsitoSyncAd(EsitoSyncAd::OK, now(), $creati, $aggiornati, $riattivati,
                $daDisattivare->count(), $daDisattivare->count(), $senzaEmail, $occupati, $errori);
        });
    }

    /**
     * Utenti di dominio attivi che oggi hanno il ruolo dato dal gruppo (nome in maiuscolo).
     * URP e segnalatori danno lo stesso ruolo: conta solo se mancano entrambi.
     *
     * @param  list<string>  $mancanti
     */
    private function utentiDelGruppo(string $gruppo, array $mancanti = []): int
    {
        $gruppi = collect((array) config('ldap.gruppi'))->map(fn ($nome) => mb_strtoupper(trim((string) $nome)));
        $chiave = $gruppi->search(fn ($nome) => $nome === $gruppo);
        if (in_array($chiave, ['urp', 'segnalatori'], true)) {
            $altro = (string) $gruppi->get($chiave === 'urp' ? 'segnalatori' : 'urp');
            if ($altro !== '' && ! in_array($altro, $mancanti, true)) {
                return 0;
            }
        }

        $q = User::where('auth_source', 'ldap')->where('attivo', true);
        $conRuolo = fn (string $ruolo) => $q->whereHas('roles', fn ($r) => $r->where('name', $ruolo));

        return match ($chiave) {
            'admin' => $conRuolo('admin')->count(),
            'supervisori' => $conRuolo('gestore')->where('supervisore_segnalazioni', true)->count(),
            'gestori' => $conRuolo('gestore')->where(fn ($w) => $w->where('supervisore_segnalazioni', false)->orWhereNull('supervisore_segnalazioni'))->count(),
            'operai' => $conRuolo('operaio')->count(),
            'urp', 'segnalatori' => $conRuolo('segnalatore')->count(),
            // Gruppo sconosciuto: prudenza, conta come grave.
            default => 1,
        };
    }

    /** @param list<array{0: DirectoryIdentity, 1: RuoloLdap}> $daAllineare */
    private function previsione(array $daAllineare, int $disattivati, int $senzaEmail): EsitoSyncAd
    {
        $esistenti = User::whereIn('ldap_guid', array_map(fn ($r) => $r[0]->guid, $daAllineare))
            ->get(['ldap_guid', 'attivo'])->keyBy('ldap_guid');

        $creati = $aggiornati = $riattivati = 0;
        foreach ($daAllineare as [$m]) {
            $u = $esistenti->get($m->guid);
            match (true) {
                $u === null => $creati++,
                ! $u->attivo => $riattivati++,
                default => $aggiornati++,
            };
        }

        return new EsitoSyncAd(EsitoSyncAd::OK, now(), $creati, $aggiornati, $riattivati,
            $disattivati, $disattivati, $senzaEmail, motivo: 'Prova: nessuna modifica scritta.');
    }
}
