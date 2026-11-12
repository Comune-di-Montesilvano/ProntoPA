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

    /**
     * @param  bool  $forza  ignora la soglia del 50% di disattivazioni
     * @param  bool  $prova  calcola senza scrivere né salvare l'esito
     */
    public function esegui(bool $forza = false, bool $prova = false): EsitoSyncAd
    {
        if (! $this->directory->syncConfigurata()) {
            return EsitoSyncAd::fallita('Sincronizzazione non configurata (LDAP_SYNC_USERNAME / LDAP_SYNC_PASSWORD).');
        }

        $lock = Cache::lock('ldap-sync', 600);
        if (! $lock->get()) {
            return EsitoSyncAd::fallita('Sincronizzazione già in corso.');
        }

        try {
            $esito = $this->giro($forza, $prova);
        } catch (\Throwable $e) {
            // Mai un fallimento silenzioso: esito salvato e admin avvisati (transazione già annullata).
            report($e);
            $esito = EsitoSyncAd::fallita('Errore imprevisto: '.$e->getMessage().' Nessun utente modificato.');
        } finally {
            $lock->release();
        }

        if (! $prova) {
            Impostazione::set(self::CHIAVE_ESITO, json_encode($esito->toArray()));
            if (! $esito->ok()) {
                Log::error('Sincronizzazione AD '.$esito->stato.': '.$esito->motivo);
            }
        }

        return $esito;
    }

    public function ultimoEsito(): ?EsitoSyncAd
    {
        $json = Impostazione::get(self::CHIAVE_ESITO);
        $dati = is_string($json) ? json_decode($json, true) : null;

        return is_array($dati) ? EsitoSyncAd::fromArray($dati) : null;
    }

    private function giro(bool $forza, bool $prova): EsitoSyncAd
    {
        try {
            $membri = $this->directory->membriGruppi();
        } catch (DirectoryNonDisponibile $e) {
            return EsitoSyncAd::fallita($e->getMessage());
        }

        // Gruppo configurato ma assente in AD (es. default PRONTOPA_URP mai creato):
        // innocuo se nessuno ha quel ruolo, altrimenti i suoi membri verrebbero disattivati.
        $mancantiConUtenti = array_values(array_filter(
            $this->directory->gruppiMancantiUltimaLettura(),
            fn (string $g) => $this->utentiDelGruppo($g) > 0,
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

        if (! $forza && $totaleAttivi >= self::MINIMO_PER_SOGLIA && $daDisattivare->count() * 2 > $totaleAttivi) {
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
            $creati = $aggiornati = $riattivati = $occupati = 0;

            foreach ($daAllineare as [$m, $ruolo]) {
                $prima = User::where('ldap_guid', $m->guid)->first(['id', 'attivo']);
                try {
                    $this->allinea->applica($m, $ruolo, login: false);
                } catch (UsernameOccupato) {
                    $occupati++;

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
                $daDisattivare->count(), $daDisattivare->count(), $senzaEmail, $occupati);
        });
    }

    /** Utenti di dominio attivi che oggi hanno il ruolo dato dal gruppo (nome in maiuscolo). */
    private function utentiDelGruppo(string $gruppo): int
    {
        $chiave = collect((array) config('ldap.gruppi'))
            ->search(fn ($nome) => mb_strtoupper(trim((string) $nome)) === $gruppo);

        $q = User::where('auth_source', 'ldap')->where('attivo', true);
        $conRuolo = fn (string $ruolo) => $q->whereHas('roles', fn ($r) => $r->where('name', $ruolo));

        return match ($chiave) {
            'admin' => $conRuolo('admin')->count(),
            'supervisori' => $conRuolo('gestore')->where('supervisore_segnalazioni', true)->count(),
            'gestori' => $conRuolo('gestore')->where(fn ($w) => $w->where('supervisore_segnalazioni', false)->orWhereNull('supervisore_segnalazioni'))->count(),
            'operai' => $conRuolo('operaio')->count(),
            'urp' => $conRuolo('segnalatore')->where('id_provenienza', MappaGruppiLdap::PROVENIENZA_URP)->count(),
            'segnalatori' => $conRuolo('segnalatore')->where(fn ($w) => $w->where('id_provenienza', '!=', MappaGruppiLdap::PROVENIENZA_URP)->orWhereNull('id_provenienza'))->count(),
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
