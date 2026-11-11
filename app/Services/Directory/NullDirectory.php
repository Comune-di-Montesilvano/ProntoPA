<?php

namespace App\Services\Directory;

/**
 * LDAP_HOST vuoto: nessuna directory, nessun dipendente autenticabile.
 * Il login ricade sugli account locali (ditte/legacy) senza errori.
 */
final class NullDirectory implements Directory
{
    public function authenticate(string $username, #[\SensitiveParameter] string $password): ?DirectoryIdentity
    {
        return null;
    }

    public function motivoUltimoRifiuto(): string
    {
        return 'LDAP_HOST non configurato: nessun login dipendenti possibile.';
    }

    public function syncConfigurata(): bool
    {
        return false;
    }

    public function gruppiMancantiUltimaLettura(): array
    {
        return [];
    }

    public function membriGruppi(): array
    {
        throw new DirectoryNonDisponibile('LDAP_HOST non configurato: nessuna sincronizzazione possibile.');
    }
}
