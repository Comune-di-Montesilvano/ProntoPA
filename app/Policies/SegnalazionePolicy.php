<?php

namespace App\Policies;

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

    public function view(User $user, Segnalazione $segnalazione): bool
    {
        if ($user->isAdmin() || $user->hasRole('admin')) {
            return true;
        }

        if ($user->isGestore() || $user->hasRole('gestore')) {
            if ($user->isSupervisore()) {
                return true;
            }
            return $segnalazione->id_operatore_assegnato === $user->id;
        }

        if ($user->hasRole('segnalatore')) {
            return $segnalazione->id_utente_segnalazione === $user->id;
        }

        if ($user->hasRole('impresa')) {
            return $segnalazione->appalto?->id_impresa === $user->id_impresa;
        }

        return false;
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

        return false;
    }

    public function delete(User $user, Segnalazione $segnalazione): bool
    {
        return $user->isAdmin() || $user->hasRole('admin');
    }
}
