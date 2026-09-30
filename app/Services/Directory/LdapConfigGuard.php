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

    /**
     * AD non accetta un simple bind col solo sAMAccountName: con "%s" nessun
     * dipendente riesce a entrare. Serve UPN (%s@dominio), DOMINIO\%s o DN.
     */
    public static function avvisoTemplate(string $ambiente, ?string $host, string $template): ?string
    {
        return $ambiente === 'production' && filled($host) && $host !== 'mock' && $template === '%s'
            ? 'LDAP_USER_DN_TEMPLATE=%s: Active Directory rifiuta il bind col solo username. Usa %s@dominio (UPN) o DOMINIO\\%s.'
            : null;
    }

    public static function avviso(string $ambiente, bool $tlsSkipVerify): ?string
    {
        return $ambiente === 'production' && $tlsSkipVerify
            ? 'LDAP_TLS_SKIP_VERIFY=true in produzione: il certificato di Active Directory non viene verificato.'
            : null;
    }
}
