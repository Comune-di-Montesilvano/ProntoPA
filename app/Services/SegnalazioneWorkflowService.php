<?php

namespace App\Services;

use App\Enums\SegnalazioneStato;
use App\Events\SegnalazionePublishedAutomatically;
use App\Jobs\InviaWebhookOutbound;
use App\Models\Azione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\ImpresaAssegnataNotification;
use App\Notifications\OperatoreAssegnatoNotification;
use App\Notifications\SegnalazioneChiusaNotification;
use App\Notifications\SegnalazioneStatoCambiato;
use App\Notifications\SquadraAssegnataNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SegnalazioneWorkflowService
{
    public function __construct(
        private readonly SlaService $sla,
    ) {}

    /**
     * Restituisce le azioni disponibili per una segnalazione in base allo stato attuale e al ruolo.
     *
     * Competenza: 0=Ente (admin/gestore), 1=Impresa, 2=Entrambi
     */
    public function getAzioniDisponibili(Segnalazione $segnalazione, User $user): Collection
    {
        $statoCorrente = $segnalazione->statoEnum();

        $query = Azione::orderBy('ordine');

        if ($user->hasRole('impresa')) {
            $query->whereIn('competenza_azione', [1, 2]);
        } else {
            $query->whereIn('competenza_azione', [0, 2]);
        }

        return $query->get()->filter(function (Azione $azione) use ($segnalazione, $user, $statoCorrente) {
            return $this->azioneApplicabile($azione, $segnalazione, $user, $statoCorrente);
        })->values();
    }

    /**
     * Azioni eseguibili senza parametri aggiuntivi (per i menu rapidi in lista).
     */
    public function getAzioniRapide(Segnalazione $segnalazione, User $user): Collection
    {
        return $this->getAzioniDisponibili($segnalazione, $user)
            ->reject(fn (Azione $a) => $a->flag_operatore || $a->flag_appalto)
            ->values();
    }

    /**
     * Esegue un'azione su una segnalazione.
     *
     * @param array $params {
     *   id_operatore?: int,
     *   id_appalto?: int,
     *   nota?: string,
     *   id_segnalazione_madre?: int,   (richiesto per segna_duplicata)
     *   id_segnalazione_correlata?: int (per collega)
     * }
     *
     * @throws ValidationException
     */
    public function eseguiAzione(
        Segnalazione $segnalazione,
        int          $idAzione,
        User         $user,
        array        $params = []
    ): void {
        $azione        = Azione::findOrFail($idAzione);
        $statoCorrente = $segnalazione->statoEnum();

        if (! $this->azioneApplicabile($azione, $segnalazione, $user, $statoCorrente)) {
            throw ValidationException::withMessages([
                'azione' => 'Azione non applicabile allo stato attuale della segnalazione.',
            ]);
        }

        // Azioni speciali senza cambio stato
        match ($azione->codice) {
            'riprendi' => $this->eseguiRiprendi($segnalazione, $azione, $user, $params),
            'collega'  => $this->eseguiCollega($segnalazione, $azione, $user, $params),
            default    => $this->eseguiTransizione($segnalazione, $azione, $user, $params),
        };
    }

    public function setEvidenza(Segnalazione $segnalazione, bool $evidenza): void
    {
        $segnalazione->update(['flag_evidenza' => $evidenza]);
    }

    // ── Azioni speciali ───────────────────────────────────────────────────────

    private function eseguiRiprendi(Segnalazione $segnalazione, Azione $azione, User $user, array $params): void
    {
        // Trova ultimo stato non-SOSPESA nel log
        $ultimoStatoPrecedente = StoricoStatoSegnalazione::where('id_segnalazione', $segnalazione->id_segnalazione)
            ->where('id_stato_segnalazione', '!=', SegnalazioneStato::SOSPESA->value)
            ->orderByDesc('data_registrazione')
            ->value('id_stato_segnalazione');

        $statoRipresa = $ultimoStatoPrecedente ?? SegnalazioneStato::IN_CARICO->value;

        $segnalazione->update(['id_stato_segnalazione' => $statoRipresa]);

        StoricoStatoSegnalazione::create([
            'id_segnalazione'       => $segnalazione->id_segnalazione,
            'id_stato_segnalazione' => $statoRipresa,
            'id_utente'             => $user->id,
            'id_utente_collegato'   => 0,
            'id_appalto'            => 0,
        ]);

        if (! empty($params['nota'])) {
            $segnalazione->note()->create([
                'testo'            => $params['nota'],
                'id_utente'        => $user->id,
                'visibile_web'     => false,
                'visibile_impresa' => false,
            ]);
        }

        InviaWebhookOutbound::dispatch($segnalazione->fresh());
    }

    private function eseguiCollega(Segnalazione $segnalazione, Azione $azione, User $user, array $params): void
    {
        $idCorrelata = (int) ($params['id_segnalazione_correlata'] ?? 0);

        if (! $idCorrelata) {
            throw ValidationException::withMessages([
                'id_segnalazione_correlata' => 'Seleziona la segnalazione a cui collegare.',
            ]);
        }

        if ($idCorrelata === $segnalazione->id_segnalazione) {
            throw ValidationException::withMessages([
                'id_segnalazione_correlata' => 'Non puoi collegare una segnalazione a se stessa.',
            ]);
        }

        $correlata = Segnalazione::find($idCorrelata);
        if (! $correlata) {
            throw ValidationException::withMessages([
                'id_segnalazione_correlata' => 'Segnalazione correlata non trovata.',
            ]);
        }

        $segnalazione->update(['id_segnalazione_correlata' => $idCorrelata]);

        if (! empty($params['nota'])) {
            $segnalazione->note()->create([
                'testo'            => $params['nota'],
                'id_utente'        => $user->id,
                'visibile_web'     => false,
                'visibile_impresa' => false,
            ]);
        }
    }

    // ── Transizione standard ──────────────────────────────────────────────────

    private function eseguiTransizione(Segnalazione $segnalazione, Azione $azione, User $user, array $params): void
    {
        $nuovoStato = SegnalazioneStato::from($azione->id_stato_segnalazione);
        $previousStateId = $segnalazione->id_stato_segnalazione instanceof SegnalazioneStato
            ? $segnalazione->id_stato_segnalazione->value
            : (int) $segnalazione->id_stato_segnalazione;

        $update = ['id_stato_segnalazione' => $nuovoStato->value];

        if ($azione->flag_operatore && ! empty($params['id_operatore'])) {
            $update['id_operatore_assegnato'] = $params['id_operatore'];
        }

        if ($azione->flag_operatore && isset($params['id_squadra']) && $params['id_squadra']
            && Impostazione::get('squadre_enabled', false)
        ) {
            $update['id_squadra_assegnata'] = $params['id_squadra'];
            if (empty($params['id_operatore'])) {
                $update['id_operatore_assegnato'] = 0;
            }
        }

        if ($azione->flag_appalto && ! empty($params['id_appalto'])) {
            $update['id_appalto'] = $params['id_appalto'];
        }

        if ($azione->flag_preventivo && isset($params['importo_preventivo'])) {
            $update['importo_preventivo'] = $params['importo_preventivo'];
        }

        // Gestione DUPLICATA: imposta la segnalazione madre
        if ($azione->codice === 'segna_duplicata') {
            $idMadre = (int) ($params['id_segnalazione_madre'] ?? 0);
            if (! $idMadre) {
                throw ValidationException::withMessages([
                    'id_segnalazione_madre' => 'Seleziona la segnalazione madre.',
                ]);
            }
            if ($idMadre === $segnalazione->id_segnalazione) {
                throw ValidationException::withMessages([
                    'id_segnalazione_madre' => 'Non puoi marcare una segnalazione come duplicata di se stessa.',
                ]);
            }
            $madre = Segnalazione::find($idMadre);
            if (! $madre || $madre->statoEnum() === SegnalazioneStato::DUPLICATA) {
                throw ValidationException::withMessages([
                    'id_segnalazione_madre' => 'La segnalazione madre non è valida.',
                ]);
            }
            $update['id_segnalazione_madre'] = $idMadre;
        }

        // Motivazione obbligatoria per ANNULLATA
        if ($azione->codice === 'annulla' && empty($params['nota'])) {
            throw ValidationException::withMessages([
                'nota' => 'La motivazione è obbligatoria per annullare una segnalazione.',
            ]);
        }

        // Gestione RIAPRI
        if ($azione->codice === 'riapri') {
            $update['data_chiusura'] = null;
            $update['sla_violato']   = false;
        }

        if ($nuovoStato->isTerminale() && $azione->codice !== 'riapri') {
            $update['data_chiusura'] = now();
            $update['sla_violato']   = false;
        } elseif ($azione->codice !== 'riapri') {
            $scadenza = $this->sla->calcolaScadenza($segnalazione);
            if ($scadenza) {
                $update['data_scadenza_sla'] = $scadenza;
            }
        }

        $segnalazione->update($update);

        StoricoStatoSegnalazione::create([
            'id_segnalazione'       => $segnalazione->id_segnalazione,
            'id_stato_segnalazione' => $nuovoStato->value,
            'id_utente'             => $user->id,
            'id_utente_collegato'   => $params['id_operatore'] ?? 0,
            'id_appalto'            => $params['id_appalto'] ?? 0,
        ]);

        $notaTesto = $params['nota'] ?? '';
        if (isset($params['ore_lavoro']) || isset($params['materiali'])) {
            $rapportinoTesto = "Rapportino di fine lavoro:\n";
            if (filled($params['ore_lavoro'] ?? null)) {
                $rapportinoTesto .= "- Ore impiegate: " . $params['ore_lavoro'] . "\n";
            }
            if (filled($params['materiali'] ?? null)) {
                $rapportinoTesto .= "- Materiali usati: " . $params['materiali'] . "\n";
            }
            $notaTesto = $rapportinoTesto . "\nDescrizione:\n" . $notaTesto;
        }

        if (! empty($notaTesto)) {
            $segnalazione->note()->create([
                'testo'            => $notaTesto,
                'id_utente'        => $user->id,
                'visibile_web'     => false,
                'visibile_impresa' => $azione->flag_notifica || isset($params['ore_lavoro']),
            ]);
        }

        $fresca = $segnalazione->fresh();

        if ($this->deveInviareNotifiche($azione, $fresca)) {
            $this->inviaNotifiche($fresca, $azione, $user);
        }

        $this->automaticallyPublish($fresca, $previousStateId, $nuovoStato->value);

        InviaWebhookOutbound::dispatch($fresca);
    }

    // ── Filtro applicabilità ─────────────────────────────────────────────────

    private function azioneApplicabile(Azione $azione, Segnalazione $segnalazione, User $user, SegnalazioneStato $statoCorrente): bool
    {
        $filtro = $azione->parametri_filtro;
        $statiConsentiti = is_array($filtro) ? ($filtro['stati'] ?? []) : [];

        if (! in_array($statoCorrente->value, $statiConsentiti, true)) {
            return false;
        }

        // Segnalazione chiusa: solo riapri applicabile
        if ($segnalazione->data_chiusura && $azione->codice !== 'riapri') {
            return false;
        }

        // RIAPRI: solo admin o gestore supervisore, solo entro il limite di giorni
        if ($azione->codice === 'riapri') {
            if (! ($user->hasRole('admin') || ($user->hasRole('gestore') && $user->isSupervisore()))) {
                return false;
            }

            $limitGiorni = (int) Impostazione::get('reopen_days_limit', 30);
            if ($limitGiorni === 0 || ! $segnalazione->data_chiusura) {
                return false;
            }

            return $segnalazione->data_chiusura->diffInDays(now()) <= $limitGiorni;
        }

        return true;
    }

    // ── Notifiche ────────────────────────────────────────────────────────────

    private function inviaNotifiche(Segnalazione $segnalazione, Azione $azione, User $attore): void
    {
        if ($segnalazione->id_squadra_assegnata && $azione->flag_operatore) {
            $squadra = Squadra::with('caposquadra')->find($segnalazione->id_squadra_assegnata);

            if ($squadra?->caposquadra && $squadra->caposquadra->attivo !== false
                && $squadra->caposquadra->id !== $attore->id
            ) {
                $squadra->caposquadra->notify(new SquadraAssegnataNotification(
                    $segnalazione->id_segnalazione,
                    $squadra->id_squadra,
                ));
            }

            return;
        }

        if ($azione->flag_operatore && $segnalazione->id_operatore_assegnato) {
            $operatore = User::find($segnalazione->id_operatore_assegnato);

            if ($operatore && $operatore->id !== $attore->id && $operatore->attivo !== false) {
                $operatore->notify(new OperatoreAssegnatoNotification($segnalazione, $azione, $attore));
            }

            return;
        }

        if ($azione->flag_appalto && $segnalazione->id_appalto) {
            $appalto = $segnalazione->appalto()->with('impresa')->first();

            if ($appalto?->id_impresa) {
                $destinatari = User::role('impresa')
                    ->where('id_impresa', $appalto->id_impresa)
                    ->get()
                    ->filter(fn (User $user) => $user->attivo !== false);

                if ($destinatari->isNotEmpty()) {
                    Notification::send($destinatari, new ImpresaAssegnataNotification($segnalazione, $azione, $attore));
                    return;
                }

                if ($appalto->impresa?->email) {
                    Notification::route('mail', $appalto->impresa->email)
                        ->notify(new ImpresaAssegnataNotification($segnalazione, $azione, $attore));
                }
            }

            return;
        }

        $statoEnum = $segnalazione->statoEnum();

        if ($statoEnum->isTerminale()) {
            $this->notificaAderenti($segnalazione, $azione, $attore);

            $segnalatore = $segnalazione->id_utente_segnalazione
                ? User::find($segnalazione->id_utente_segnalazione)
                : null;

            if ($segnalatore && $segnalatore->id !== $attore->id && $segnalatore->attivo !== false) {
                $segnalatore->notify(new SegnalazioneChiusaNotification($segnalazione, $azione, $attore));
                return;
            }

            if ($segnalazione->email) {
                Notification::route('mail', $segnalazione->email)
                    ->notify(new SegnalazioneChiusaNotification($segnalazione, $azione, $attore));
            }

            return;
        }

        $notification = new SegnalazioneStatoCambiato($segnalazione, $azione, $attore);

        if ($segnalazione->id_operatore_assegnato &&
            $segnalazione->id_operatore_assegnato !== $attore->id
        ) {
            User::find($segnalazione->id_operatore_assegnato)?->notify($notification);
        }

        if ($segnalazione->id_utente_segnalazione &&
            $segnalazione->id_utente_segnalazione !== $attore->id
        ) {
            User::find($segnalazione->id_utente_segnalazione)?->notify($notification);
        }

        if ($segnalazione->id_appalto) {
            $appalto = $segnalazione->appalto()->with('impresa')->first();
            if ($appalto?->id_impresa) {
                $destinatari = User::role('impresa')
                    ->where('id_impresa', $appalto->id_impresa)
                    ->where('id', '!=', $attore->id)
                    ->get()
                    ->filter(fn (User $u) => $u->attivo !== false);

                if ($destinatari->isNotEmpty()) {
                    Notification::send($destinatari, $notification);
                }
            }
        }
    }

    private function deveInviareNotifiche(Azione $azione, Segnalazione $segnalazione): bool
    {
        return $azione->flag_notifica
            || ($azione->flag_operatore && (bool) $segnalazione->id_operatore_assegnato)
            || ($azione->flag_appalto && (bool) $segnalazione->id_appalto)
            || $segnalazione->statoEnum()->isTerminale()
            || ($azione->flag_operatore && (bool) $segnalazione->id_squadra_assegnata);
    }

    private function notificaAderenti(Segnalazione $segnalazione, Azione $azione, User $attore): void
    {
        foreach ($segnalazione->adesioni()->with('utente')->get() as $adesione) {
            if ($adesione->segnalante !== null) {
                if ($adesione->email) {
                    Notification::route('mail', $adesione->email)
                        ->notify(new SegnalazioneChiusaNotification($segnalazione, $azione, $attore));
                }
                continue;
            }

            $utente = $adesione->utente;
            if ($utente
                && $utente->id !== $attore->id
                && $utente->id !== $segnalazione->id_utente_segnalazione
                && $utente->attivo !== false
            ) {
                $utente->notify(new SegnalazioneChiusaNotification($segnalazione, $azione, $attore));
            }
        }
    }

    private function automaticallyPublish(Segnalazione $segnalazione, int $previousStateId, int $newStateId): void
    {
        if (! Impostazione::get('publication_enabled', false)) {
            return;
        }

        $triggerStateId = Impostazione::get('publication_auto_state_id');

        if ($triggerStateId === null) {
            return;
        }

        if ($newStateId >= (int) $triggerStateId && ! $segnalazione->flag_pubblicata) {
            $segnalazione->update([
                'flag_pubblicata' => true,
                'flag_riservata'  => false,
            ]);

            Cache::forget('public.home.statistics');

            SegnalazionePublishedAutomatically::dispatch(
                $segnalazione->fresh(),
                $previousStateId,
                $newStateId,
            );
        }
    }
}
