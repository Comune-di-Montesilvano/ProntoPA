<?php

namespace App\Services\Directory;

use RuntimeException;

final class LdapConfigGuard
{
    public static function verifica(string $ambiente, ?string $host): void
    {
        if ($ambiente === 'production' && $host === 'mock') {
            throw new RuntimeException('LDAP_HOST=mock non è ammesso in produzione: configura il server AD reale.');
        }
    }

    public static function avviso(string $ambiente, bool $tlsSkipVerify): ?string
    {
        return $ambiente === 'production' && $tlsSkipVerify
            ? 'LDAP_TLS_SKIP_VERIFY=true in produzione: il certificato di Active Directory non viene verificato.'
            : null;
    }
}
