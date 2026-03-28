<?php

namespace App\Policies;

use App\Models\User;

class SegnalazionePolicy
{
    /**
     * Admin e gestori supervisori vedono tutte le segnalazioni.
     * Gestori non supervisori vedono solo quelle assegnate a loro.
     * Segnalatori vedono solo le proprie.
     * Imprese vedono solo quelle assegnate alla propria impresa.
     */
    public function viewAny(User $user): bool
    {
        return true; // filtro applicato nel model scope
    }

    public function view(User $user, object $segnalazione): bool
    {
        if ($user->hasRole('admin') || $user->isAdmin()) {
            return true;
        }

        if ($user->hasRole('gestore') || $user->isGestore()) {
            // supervisore vede tutto
            if ($user->isSupervisore()) {
                return true;
            }
            // gestore non supervisore: solo assegnate a lui
            return $segnalazione->id_operatore === $user->id;
        }

        if ($user->hasRole('segnalatore')) {
            return $segnalazione->id_utente === $user->id;
        }

        if ($user->hasRole('impresa')) {
            return $segnalazione->id_impresa === $user->id_impresa ?? false;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin')
            || $user->hasRole('gestore')
            || $user->hasRole('segnalatore')
            || $user->isAdmin()
            || $user->isGestore();
    }

    public function update(User $user, object $segnalazione): bool
    {
        if ($user->hasRole('admin') || $user->isAdmin()) {
            return true;
        }

        if ($user->hasRole('gestore') || $user->isGestore()) {
            return $user->isSupervisore() || $segnalazione->id_operatore === $user->id;
        }

        return false;
    }

    public function delete(User $user, object $segnalazione): bool
    {
        return $user->hasRole('admin') || $user->isAdmin();
    }
}
