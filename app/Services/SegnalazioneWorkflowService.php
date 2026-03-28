<?php

namespace App\Services;

use App\Models\Azione;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\SegnalazioneStatoCambiato;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SegnalazioneWorkflowService
{
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

        // Notifiche email
        if ($azione->flag_notifica) {
            $this->inviaNotifiche($segnalazione->fresh(), $azione, $user);
        }
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
}
