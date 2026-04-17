<?php

namespace App\Services;

use App\Events\SegnalazionePublishedAutomatically;
use App\Models\Azione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\ImpresaAssegnataNotification;
use App\Notifications\OperatoreAssegnatoNotification;
use App\Notifications\SegnalazioneChiusaNotification;
use App\Notifications\SegnalazioneStatoCambiato;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SegnalazioneWorkflowService
{
    public function __construct(
        private readonly WebhookService $webhook,
    ) {}

    /**
     * Restituisce le azioni disponibili per una segnalazione in base al ruolo utente.
     * - competenza_azione 0 = Ente (admin/gestore)
     * - competenza_azione 1 = Impresa
     * - competenza_azione 2 = Entrambi
     */
    public function getAzioniDisponibili(Segnalazione $segnalazione, User $user): Collection
    {
        if ($segnalazione->isChiusa()) {
            return collect();
        }

        $query = Azione::orderBy('ordine');

        if ($user->hasRole('impresa')) {
            $query->whereIn('competenza_azione', [1, 2]);
        } else {
            // admin / gestore
            $query->whereIn('competenza_azione', [0, 2]);
        }

        return $query->get();
    }

    /**
     * Esegue un'azione su una segnalazione.
     *
     * @param array $params {
     *   id_operatore?: int,
     *   id_appalto?: int,
     *   nota?: string
     * }
     *
     * @throws ValidationException se l'azione non è applicabile
     */
    public function eseguiAzione(
        Segnalazione $segnalazione,
        int          $idAzione,
        User         $user,
        array        $params = []
    ): void {
        $azione = Azione::findOrFail($idAzione);

        if ($segnalazione->isChiusa()) {
            throw ValidationException::withMessages([
                'azione' => 'Impossibile eseguire azioni su una segnalazione chiusa.',
            ]);
        }

        $statoTarget = $azione->statoTarget;

        // Aggiornamenti sulla segnalazione
        $update = ['id_stato_segnalazione' => $statoTarget->id_stato];

        if ($azione->flag_operatore && isset($params['id_operatore']) && $params['id_operatore']) {
            $update['id_operatore_assegnato'] = $params['id_operatore'];
        }

        if ($azione->flag_appalto && isset($params['id_appalto']) && $params['id_appalto']) {
            $update['id_appalto'] = $params['id_appalto'];
        }

        if ($statoTarget->chiusura) {
            $update['data_chiusura'] = now();
        }

        $segnalazione->update($update);

        // Audit trail
        StoricoStatoSegnalazione::create([
            'id_segnalazione'      => $segnalazione->id_segnalazione,
            'id_stato_segnalazione'=> $statoTarget->id_stato,
            'id_utente'            => $user->id,
            'id_utente_collegato'  => $params['id_operatore'] ?? 0,
            'id_appalto'           => $params['id_appalto'] ?? 0,
        ]);

        // Nota automatica se fornita
        if (! empty($params['nota'])) {
            $segnalazione->note()->create([
                'testo'            => $params['nota'],
                'id_utente'        => $user->id,
                'visibile_web'     => false,
                'visibile_impresa' => $azione->flag_notifica,
            ]);
        }

        $fresca = $segnalazione->fresh();

        // Notifiche email
        if ($this->deveInviareNotifiche($azione, $fresca)) {
            $this->inviaNotifiche($fresca, $azione, $user);
        }

        // Pubblicazione automatica se configurata
        $previousStateId = $segnalazione->getOriginal('id_stato_segnalazione');
        $this->automaticallyPublish($fresca, $previousStateId, $statoTarget->id_stato);

        // Webhook outbound verso sito Comune
        $this->webhook->notificaCambioStato($fresca);
    }

    /**
     * Imposta / rimuove il flag evidenza.
     */
    public function setEvidenza(Segnalazione $segnalazione, bool $evidenza): void
    {
        $segnalazione->update(['flag_evidenza' => $evidenza]);
    }

    // ── Privati ───────────────────────────────────────────────────────────────

    private function inviaNotifiche(Segnalazione $segnalazione, Azione $azione, User $attore): void
    {
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

        if ($segnalazione->stato?->chiusura) {
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

        // All'operatore assegnato (se diverso dall'attore)
        if ($segnalazione->id_operatore_assegnato &&
            $segnalazione->id_operatore_assegnato !== $attore->id
        ) {
            $operatore = User::find($segnalazione->id_operatore_assegnato);
            $operatore?->notify($notification);
        }

        // Al segnalatore originale (se visibile_web implicito dal flag)
        if ($segnalazione->id_utente_segnalazione &&
            $segnalazione->id_utente_segnalazione !== $attore->id
        ) {
            $segnalatore = User::find($segnalazione->id_utente_segnalazione);
            $segnalatore?->notify($notification);
        }
    }

    private function deveInviareNotifiche(Azione $azione, Segnalazione $segnalazione): bool
    {
        return $azione->flag_notifica
            || ($azione->flag_operatore && (bool) $segnalazione->id_operatore_assegnato)
            || ($azione->flag_appalto && (bool) $segnalazione->id_appalto)
            || (bool) $segnalazione->stato?->chiusura;
    }

    /**
     * Pubblica automaticamente una segnalazione se configurato.
     * Una segnalazione viene pubblicata quando raggiunge uno stato configurato in impostazioni.
     */
    private function automaticallyPublish(
        Segnalazione $segnalazione,
        int|null     $previousStateId,
        int          $newStateId,
    ): void {
        // Controlla se la pubblicazione automatica è abilitata
        if (! Impostazione::get('publication_enabled', false)) {
            return;
        }

        // Leggi lo stato trigger configurato
        $triggerStateId = Impostazione::get('publication_auto_state_id');

        if ($triggerStateId === null) {
            return;
        }

        // Se siamo già al di sopra del trigger e il nuovo stato è >= trigger, pubblica
        if ($newStateId >= (int) $triggerStateId && ! $segnalazione->flag_pubblicata) {
            $segnalazione->update([
                'flag_pubblicata' => true,
                'flag_riservata'  => false,
            ]);

            Cache::forget('public.home.statistics');

            // Emetti evento per audit trail
            SegnalazionePublishedAutomatically::dispatch(
                $segnalazione->fresh(),
                $previousStateId ?? 0,
                $newStateId,
            );
        }
    }
}

