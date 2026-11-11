<?php

namespace App\Services\Directory;

use LdapRecord\Auth\BindException;
use LdapRecord\Connection;
use LdapRecord\LdapRecordException;
use LdapRecord\Models\Attributes\Guid;
use Illuminate\Support\Facades\Log;

/**
 * Active Directory reale. Login: bind con le credenziali dell'utente, poi
 * lettura attributi e gruppi annidati via LDAP_MATCHING_RULE_IN_CHAIN
 * (1.2.840.113556.1.4.1941). Sync: bind con l'account di servizio in sola
 * lettura (config ldap.sync) ed elenco dei membri dei gruppi ProntoPA.
 */
final class LdapRecordDirectory implements Directory
{
    /** LDAP_INVALID_CREDENTIALS: su AD anche account disabilitato/scaduto/bloccato. */
    private const ERRORE_CREDENZIALI = 49;

    /** userAccountControl ACCOUNTDISABLE. */
    private const UAC_DISABILITATO = 0x2;

    private const IN_CHAIN = '1.2.840.113556.1.4.1941';

    /**
     * @param  array{host: string, port: int, base_dn: ?string, user_dn_template: string, starttls: bool, tls_skip_verify: bool, timeout: int, sync?: array{username: ?string, password: ?string}, gruppi?: array<string, ?string>}  $config
     */
    public function __construct(private readonly array $config) {}

    private ?string $motivo = null;

    public function motivoUltimoRifiuto(): ?string
    {
        return $this->motivo;
    }

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

    public function authenticate(string $username, #[\SensitiveParameter] string $password): ?DirectoryIdentity
    {
        $this->motivo = null;

        if ($password === '' || trim($username) === '') {
            $this->motivo = 'Username o password vuoti.';

            return null;
        }

        $connection = $this->connessione();

        $bindDn = self::bindDn($this->config['user_dn_template'], $username);
        [$attributo, $valore] = self::filtroIdentita($this->config['user_dn_template'], $username);

        try {
            try {
                $connection->auth()->bind($bindDn, $password);
            } catch (BindException $e) {
                $codice = $e->getDetailedError()?->getErrorCode() ?? (int) $e->getCode();

                if (self::credenzialiNonValide($codice)) {
                    // AD dettaglia il motivo nel messaggio diagnostico (data 52e
                    // password errata, 525 utente inesistente, 533 disabilitato,
                    // 701/532 scaduto, 775 bloccato).
                    $this->motivo = sprintf('Bind rifiutato da AD (codice 49) per "%s": %s',
                        $bindDn, $e->getDetailedError()?->getDiagnosticMessage() ?: $e->getMessage());

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
                // Password giusta ma ricerca vuota: base DN o template errati.
                $this->motivo = sprintf('Bind riuscito ma nessun utente con %s=%s sotto "%s": controlla LDAP_BASE_DN e LDAP_USER_DN_TEMPLATE.',
                    $attributo, $valore, (string) $this->config['base_dn']);
                Log::warning('LDAP: '.$this->motivo);

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

    private function connessione(): Connection
    {
        $endpoint = self::endpoint($this->config['host'], $this->config['port']);

        return new Connection([
            'hosts' => [$endpoint['host']],
            'port' => $endpoint['port'],
            'base_dn' => (string) $this->config['base_dn'],
            // LdapRecord v4: use_tls = ldaps://, use_starttls = upgrade su ldap://
            'use_tls' => $endpoint['ssl'],
            'use_starttls' => $this->config['starttls'],
            'timeout' => $this->config['timeout'],
            'follow_referrals' => false,
            'options' => [
                LDAP_OPT_X_TLS_REQUIRE_CERT => $this->config['tls_skip_verify'] ? LDAP_OPT_X_TLS_NEVER : LDAP_OPT_X_TLS_HARD,
                LDAP_OPT_NETWORK_TIMEOUT => $this->config['timeout'],
            ],
        ]);
    }

    /**
     * Gruppi configurati (non vuoti) che la ricerca non ha trovato, in maiuscolo.
     *
     * @param  list<string>  $configurati
     * @param  list<string>  $trovati
     * @return list<string>
     */
    public static function gruppiMancanti(array $configurati, array $trovati): array
    {
        $norm = fn (string $g): string => mb_strtoupper(trim($g));
        $trovati = array_map($norm, $trovati);

        return array_values(array_filter(
            array_map($norm, $configurati),
            fn (string $g): bool => $g !== '' && ! in_array($g, $trovati, true),
        ));
    }

    public static function disabilitato(?string $userAccountControl): bool
    {
        return $userAccountControl !== null && ((int) $userAccountControl & self::UAC_DISABILITATO) !== 0;
    }

    public function syncConfigurata(): bool
    {
        return filled($this->config['sync']['username'] ?? null) && filled($this->config['sync']['password'] ?? null);
    }

    /** Bind dell'account di servizio: qui ogni rifiuto è un errore di configurazione. */
    public function bindServizio(Connection $connection, string $utente, #[\SensitiveParameter] string $password): void
    {
        try {
            $connection->auth()->bind($utente, $password);
        } catch (BindException $e) {
            throw new DirectoryNonDisponibile(sprintf('Bind dell\'account di servizio "%s" rifiutato: %s',
                $utente, $e->getDetailedError()?->getDiagnosticMessage() ?: $e->getMessage()), 0, $e);
        }
    }

    public function membriGruppi(): array
    {
        if (! $this->syncConfigurata()) {
            throw new DirectoryNonDisponibile('Account di servizio non configurato (LDAP_SYNC_USERNAME / LDAP_SYNC_PASSWORD).');
        }

        $connection = $this->connessione();

        /** @var array<string, array{0: array<string, array<int, string>>, 1: list<string>}> $perGuid */
        $perGuid = [];
        $configurati = array_map('strval', array_values((array) ($this->config['gruppi'] ?? config('ldap.gruppi'))));
        /** @var list<string> $trovati */
        $trovati = [];

        try {
            $this->bindServizio($connection, (string) $this->config['sync']['username'], (string) $this->config['sync']['password']);

            foreach ($configurati as $nomeGruppo) {
                $nomeGruppo = trim((string) $nomeGruppo);
                if ($nomeGruppo === '') {
                    continue;
                }

                /** @var array<string, array<int, string>>|null $gruppo */
                $gruppo = $connection->query()
                    ->rawFilter('(&(objectClass=group)(cn='.ldap_escape($nomeGruppo, '', LDAP_ESCAPE_FILTER).'))')
                    ->select(['distinguishedname'])
                    ->first();

                if (! $gruppo) {
                    // Gruppo rinominato/assente/fuori dal base DN: i suoi membri sparirebbero
                    // dall'elenco e verrebbero disattivati. Errore dopo il giro (gruppiMancanti).
                    continue;
                }
                $trovati[] = $nomeGruppo;

                $dnGruppo = $gruppo['distinguishedname'][0];

                // isCritical marca il controllo di paging come critico verso il server;
                // gli errori di ldap_search arrivano come \ErrorException (catch sotto).
                /** @var list<array<string, array<int, string>>> $utenti */
                $utenti = $connection->query()
                    ->rawFilter('(&(objectCategory=person)(objectClass=user)(memberOf:'.self::IN_CHAIN.':='.ldap_escape($dnGruppo, '', LDAP_ESCAPE_FILTER).'))')
                    ->select(['objectguid', 'samaccountname', 'displayname', 'mail', 'useraccountcontrol'])
                    ->paginate(500, true);

                foreach ($utenti as $u) {
                    if (empty($u['objectguid'][0]) || empty($u['samaccountname'][0])) {
                        continue;
                    }
                    $guid = (new Guid($u['objectguid'][0]))->getValue();
                    $perGuid[$guid] ??= [$u, []];
                    $perGuid[$guid][1][] = $nomeGruppo;
                }
            }
        } catch (DirectoryNonDisponibile $e) {
            throw $e;
        } catch (LdapRecordException $e) {
            throw new DirectoryNonDisponibile('Active Directory non raggiungibile: '.$e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            // ldap_search() emette warning convertiti in \ErrorException, non LdapRecordException.
            throw new DirectoryNonDisponibile('Errore nella lettura dei gruppi da Active Directory: '.$e->getMessage(), 0, $e);
        } finally {
            $connection->disconnect();
        }

        $mancanti = self::gruppiMancanti($configurati, $trovati);
        if ($mancanti !== []) {
            throw new DirectoryNonDisponibile(sprintf(
                'Gruppi non trovati in Active Directory: %s. Crea il gruppo sotto LDAP_BASE_DN, oppure svuota la variabile LDAP_GRUPPO_* corrispondente se non lo usi. Nessun utente modificato.',
                implode(', ', $mancanti)));
        }

        $membri = [];
        foreach ($perGuid as $guid => [$u, $gruppi]) {
            $membri[] = new DirectoryIdentity(
                guid: (string) $guid,
                username: $u['samaccountname'][0],
                name: $u['displayname'][0] ?? $u['samaccountname'][0],
                email: $u['mail'][0] ?? null,
                groups: array_values(array_unique($gruppi)),
                abilitato: ! self::disabilitato($u['useraccountcontrol'][0] ?? null),
            );
        }

        return $membri;
    }
}
