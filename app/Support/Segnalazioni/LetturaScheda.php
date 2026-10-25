<?php

namespace App\Support\Segnalazioni;

use App\Models\User;

/**
 * Quale lettura della scheda vede l'utente: pratica completa (comune),
 * ordine di lavoro (ditta, operaio) o tracciamento (chi ha segnalato).
 */
class LetturaScheda
{
    /** @return 'gestore'|'lavoro'|'segnalante' */
    public static function per(User $user): string
    {
        if ($user->isAdmin() || $user->isGestore() || $user->hasAnyRole(['admin', 'gestore'])) {
            return 'gestore';
        }

        if ($user->hasAnyRole(['operaio', 'impresa'])) {
            return 'lavoro';
        }

        return 'segnalante';
    }
}
