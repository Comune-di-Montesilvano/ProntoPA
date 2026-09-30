<?php

namespace App\Services\Directory;

/**
 * Simulatore AD per sviluppo locale (LDAP_HOST=mock). Password = username.
 * Rifiutato in produzione da LdapConfigGuard. Prefisso "mock." per non
 * collidere con gli utenti locali creati da `artisan demo`.
 */
final class MockDirectory implements Directory
{
    private const UTENTI = [
        'admin' => 'PRONTOPA_ADMIN',
        'mock.supervisore' => 'PRONTOPA_SUPERVISORI',
        'mock.gestore' => 'PRONTOPA_GESTORI',
        'mock.operaio' => 'PRONTOPA_OPERAI',
        'mock.urp' => 'PRONTOPA_URP',
        'mock.segnalatore' => 'PRONTOPA_SEGNALATORI',
        'mock.nessungruppo' => null,
    ];

    public function authenticate(string $username, string $password): ?DirectoryIdentity
    {
        $username = mb_strtolower(trim($username));

        if (! array_key_exists($username, self::UTENTI) || $password !== $username) {
            return null;
        }

        $gruppo = self::UTENTI[$username];

        return new DirectoryIdentity(
            guid: $this->guid($username),
            username: $username,
            name: 'Mock '.ucfirst(str_replace('mock.', '', $username)),
            email: $username.'@mock.local',
            groups: $gruppo !== null ? [$gruppo] : [],
        );
    }

    private function guid(string $username): string
    {
        $h = md5('prontopa-mock-'.$username);

        return sprintf('%s-%s-%s-%s-%s',
            substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    }
}
