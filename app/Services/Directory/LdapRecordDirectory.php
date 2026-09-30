<?php

namespace App\Services\Directory;

use LdapRecord\Auth\BindException;
use LdapRecord\Connection;
use LdapRecord\LdapRecordException;
use LdapRecord\Models\Attributes\Guid;

/**
 * Active Directory reale. Bind con le credenziali dell'utente (nessun
 * account di servizio), poi lettura attributi e gruppi annidati via
 * LDAP_MATCHING_RULE_IN_CHAIN (1.2.840.113556.1.4.1941).
 */
final class LdapRecordDirectory implements Directory
{
    /** LDAP_INVALID_CREDENTIALS: su AD anche account disabilitato/scaduto/bloccato. */
    private const ERRORE_CREDENZIALI = 49;

    /**
     * @param  array{host: string, port: int, base_dn: ?string, user_dn_template: string, starttls: bool, tls_skip_verify: bool, timeout: int}  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * @return array{host: string, port: int, ssl: bool}
     */
    public static function endpoint(string $host, int $portaDefault): array
    {
        $ssl = str_starts_with(strtolower($host), 'ldaps://');
        $senzaSchema = (string) preg_replace('#^ldaps?://#i', '', $host);
        [$nome, $porta] = array_pad(explode(':', $senzaSchema, 2), 2, null);

        return [
            'host' => (string) $nome,
            'port' => $porta !== null ? (int) $porta : ($ssl ? 636 : $portaDefault),
            'ssl' => $ssl,
        ];
    }

    /**
     * Guard::attempt() di LdapRecord tratta ogni BindException come
     * "credenziali errate", anche con server irraggiungibile: per questo
     * facciamo bind() diretto e distinguiamo il codice d'errore.
     */
    public static function credenzialiNonValide(int $codiceErrore): bool
    {
        return $codiceErrore === self::ERRORE_CREDENZIALI;
    }

    /** Il template DN (CN=%s,...) richiede l'escape DN dello username. */
    public static function bindDn(string $template, string $username): string
    {
        $valore = str_contains($template, '=') ? ldap_escape($username, '', LDAP_ESCAPE_DN) : $username;

        return sprintf($template, $valore);
    }

    /**
     * Attributo + valore per rileggere ESATTAMENTE il principal che ha fatto
     * bind: con il template UPN il prefisso digitato può non coincidere col
     * sAMAccountName di nessuno, o coincidere con quello di un'ALTRA persona.
     *
     * @return array{0: string, 1: string}
     */
    public static function filtroIdentita(string $template, string $username): array
    {
        return match (true) {
            str_contains($template, '=') => ['distinguishedname', self::bindDn($template, $username)],
            str_contains($template, '@') => ['userprincipalname', sprintf($template, $username)],
            default => ['samaccountname', $username],
        };
    }

    public function authenticate(string $username, string $password): ?DirectoryIdentity
    {
        if ($password === '' || trim($username) === '') {
            return null;
        }

        $endpoint = self::endpoint($this->config['host'], $this->config['port']);

        $connection = new Connection([
            'hosts' => [$endpoint['host']],
            'port' => $endpoint['port'],
            'base_dn' => (string) $this->config['base_dn'],
            'use_ssl' => $endpoint['ssl'],
            'use_tls' => $this->config['starttls'],
            'timeout' => $this->config['timeout'],
            'follow_referrals' => false,
            'options' => [
                LDAP_OPT_X_TLS_REQUIRE_CERT => $this->config['tls_skip_verify'] ? LDAP_OPT_X_TLS_NEVER : LDAP_OPT_X_TLS_HARD,
                LDAP_OPT_NETWORK_TIMEOUT => $this->config['timeout'],
            ],
        ]);

        $bindDn = self::bindDn($this->config['user_dn_template'], $username);
        [$attributo, $valore] = self::filtroIdentita($this->config['user_dn_template'], $username);

        try {
            try {
                $connection->auth()->bind($bindDn, $password);
            } catch (BindException $e) {
                $codice = $e->getDetailedError()?->getErrorCode() ?? (int) $e->getCode();

                if (self::credenzialiNonValide($codice)) {
                    return null;
                }

                throw $e;
            }

            /** @var array<string, array<int, string>>|null $entry */
            $entry = $connection->query()
                ->where($attributo, '=', $valore)
                ->select(['objectguid', 'samaccountname', 'displayname', 'mail', 'distinguishedname'])
                ->first();

            if (! $entry) {
                return null;
            }

            $dn = $entry['distinguishedname'][0];

            /** @var array<int, array<string, array<int, string>>> $gruppi */
            $gruppi = $connection->query()
                ->rawFilter('(&(objectClass=group)(member:1.2.840.113556.1.4.1941:='.ldap_escape($dn, '', LDAP_ESCAPE_FILTER).'))')
                ->select(['cn'])
                ->get();
        } catch (LdapRecordException $e) {
            throw new DirectoryNonDisponibile('Active Directory non raggiungibile: '.$e->getMessage(), 0, $e);
        } finally {
            $connection->disconnect();
        }

        return new DirectoryIdentity(
            guid: (new Guid($entry['objectguid'][0]))->getValue(),
            username: $entry['samaccountname'][0],
            name: $entry['displayname'][0] ?? $entry['samaccountname'][0],
            email: $entry['mail'][0] ?? null,
            groups: array_values(array_filter(array_map(fn ($g) => $g['cn'][0] ?? null, $gruppi))),
        );
    }
}
