<?php

namespace App\Support\Segnalazioni;

use App\Models\Impostazione;
use App\Models\Squadra;
use App\Models\User;
use Closure;

/** Validazione di "a chi" nel pannello Assegna e nelle azioni multiple: stessi elenchi che l'utente vede. */
final class RegoleAssegnazione
{
    /** @return list<mixed> */
    public static function operatore(): array
    {
        return ['integer', function (string $attributo, mixed $valore, Closure $fallisci): void {
            if (! User::assegnabili()->whereKey((int) $valore)->exists()) {
                $fallisci('Questa persona non può ricevere lavori.');
            }
        }];
    }

    /** @return list<mixed> */
    public static function squadra(): array
    {
        return ['integer', function (string $attributo, mixed $valore, Closure $fallisci): void {
            if (! Impostazione::get('squadre_enabled', false)) {
                $fallisci('Le squadre non sono attive.');

                return;
            }
            if (! Squadra::whereKey((int) $valore)->where('attiva', true)->exists()) {
                $fallisci('Squadra non trovata o non attiva.');
            }
        }];
    }
}
