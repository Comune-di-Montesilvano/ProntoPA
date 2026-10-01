<?php

namespace App\Services\Directory;

/**
 * LDAP_HOST vuoto: nessuna directory, nessun dipendente autenticabile.
 * Il login ricade sugli account locali (ditte/legacy) senza errori.
 */
final class NullDirectory implements Directory
{
    public function authenticate(string $username, string $password): ?DirectoryIdentity
    {
        return null;
    }

    public function motivoUltimoRifiuto(): string
    {
        return 'LDAP_HOST non configurato: nessun login dipendenti possibile.';
    }
}
