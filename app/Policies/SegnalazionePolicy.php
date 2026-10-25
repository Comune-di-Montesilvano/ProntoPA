<?php

namespace App\Policies;

use App\Enums\SegnalazioneStato;
use App\Models\Segnalazione;
use App\Models\User;

class SegnalazionePolicy
{
    /**
     * Admin e gestori supervisori vedono tutte le segnalazioni.
     * Gestori non supervisori vedono solo quelle assegnate a loro.
     * Segnalatori vedono solo le proprie.
     * Imprese vedono solo quelle assegnate alla propria impresa (via appalto).
     */
    public function viewAny(User $user): bool
    {
        return true; // filtro applicato nel model scope
    }

    /**
     * Una sola regola: si apre ciò che si vede in elenco (Segnalazione::scopeVisibileA).
     */
    public function view(User $user, Segnalazione $segnalazione): bool
    {
        if ($user->isAdmin() || $user->hasRole('admin')) {
            return true;
        }

        return Segnalazione::visibileA($user)->whereKey($segnalazione->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin()
            || $user->isGestore()
            || $user->hasRole('admin')
            || $user->hasRole('gestore')
            || $user->hasRole('segnalatore');
    }

    public function update(User $user, Segnalazione $segnalazione): bool
    {
        if ($user->isAdmin() || $user->hasRole('admin')) {
            return true;
        }

        if ($user->isGestore() || $user->hasRole('gestore')) {
            return $user->isSupervisore()
                || $segnalazione->id_operatore_assegnato === $user->id;
        }

        if ($user->hasRole('impresa')) {
            return $segnalazione->appalto?->id_impresa === $user->id_impresa;
        }

        return false;
    }

    /**
     * Priorità e urgenza le decide il comune (admin o gestore che può gestirla),
     * non chi segnala né chi esegue il lavoro.
     */
    public function modificaPriorita(User $user, Segnalazione $segnalazione): bool
    {
        if (! ($user->isAdmin() || $user->hasRole('admin') || $user->isGestore() || $user->hasRole('gestore'))) {
            return false;
        }

        return $this->update($user, $segnalazione);
    }

    /** Ditta o operaio assegnati comunicano la fine dei lavori (rapportino), senza chiudere. */
    public function comunicaFineLavori(User $user, Segnalazione $segnalazione): bool
    {
        if ($segnalazione->isChiusa() || ! in_array($segnalazione->statoEnum(), [
            SegnalazioneStato::ASSEGNATA_OPERATORE, SegnalazioneStato::ASSEGNATA_IMPRESA,
        ], true)) {
            return false;
        }

        if ($user->hasRole('impresa')) {
            return $user->id_impresa !== null && $segnalazione->appalto?->id_impresa === $user->id_impresa;
        }

        return $user->hasRole('operaio') && $this->view($user, $segnalazione);
    }

    /** Il comune sollecita chi ha il lavoro. */
    public function sollecita(User $user, Segnalazione $segnalazione): bool
    {
        return ! $segnalazione->isChiusa()
            && in_array($segnalazione->statoEnum(), [
                SegnalazioneStato::ASSEGNATA_OPERATORE, SegnalazioneStato::ASSEGNATA_IMPRESA, SegnalazioneStato::PREVENTIVO_IN_ATTESA,
            ], true)
            && $this->modificaPriorita($user, $segnalazione);
    }

    /** Il comune accorpa una segnalazione aperta a un'altra già esistente. */
    public function accorpa(User $user, Segnalazione $segnalazione): bool
    {
        return ! $segnalazione->isChiusa() && $this->modificaPriorita($user, $segnalazione);
    }

    public function delete(User $user, Segnalazione $segnalazione): bool
    {
        return $user->isAdmin() || $user->hasRole('admin');
    }

    /**
     * Può eliminare un allegato:
     * - admin sempre
     * - gestore supervisore sempre
     * - gestore assegnato a quella segnalazione
     * - segnalatore che ha creato la segnalazione
     */
    /**
     * Può aderire a una segnalazione:
     * - admin e gestori sempre
     * - segnalatori: se è la propria OPPURE la segnalazione non è riservata
     * - imprese: no (non pertinente)
     */
    public function adhere(User $user, Segnalazione $segnalazione): bool
    {
        if ($user->isAdmin() || $user->hasRole('admin')) {
            return true;
        }

        if ($user->isGestore() || $user->hasRole('gestore')) {
            return true;
        }

        if ($user->hasRole('segnalatore')) {
            return $segnalazione->id_utente_segnalazione === $user->id
                || ! $segnalazione->flag_riservata;
        }

        return false;
    }

    public function deleteAllegato(User $user, Segnalazione $segnalazione): bool
    {
        if ($user->isAdmin() || $user->hasRole('admin')) {
            return true;
        }

        if ($user->isGestore() || $user->hasRole('gestore')) {
            return $user->isSupervisore()
                || $segnalazione->id_operatore_assegnato === $user->id;
        }

        if ($user->hasRole('segnalatore')) {
            return $segnalazione->id_utente_segnalazione === $user->id;
        }

        return false;
    }
}
