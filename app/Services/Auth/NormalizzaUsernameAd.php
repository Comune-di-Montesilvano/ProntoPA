<?php

namespace App\Services\Auth;

/**
 * I dipendenti scrivono lo username in tre modi: "m.rossi", l'UPN
 * "m.rossi@ente.local" (che contiene @ ma NON è un'email ditta) o
 * "ENTE\m.rossi". Tutti diventano "m.rossi".
 */
final class NormalizzaUsernameAd
{
    public static function èUpn(string $login, string $template): bool
    {
        if (! str_starts_with($template, '%s@')) {
            return false;
        }

        $suffisso = mb_strtolower(substr($template, 3));

        return str_ends_with(mb_strtolower(trim($login)), '@'.$suffisso);
    }

    public static function normalizza(string $login, string $template): string
    {
        $login = trim($login);

        if (str_contains($login, '\\')) {
            $login = substr($login, strrpos($login, '\\') + 1);
        }

        if (self::èUpn($login, $template)) {
            $login = substr($login, 0, (int) strrpos($login, '@'));
        }

        return $login;
    }
}
