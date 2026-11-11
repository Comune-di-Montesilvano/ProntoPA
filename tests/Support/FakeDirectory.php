<?php

namespace Tests\Support;

use App\Services\Directory\Directory;
use App\Services\Directory\DirectoryIdentity;
use App\Services\Directory\DirectoryNonDisponibile;

final class FakeDirectory implements Directory
{
    /** @var array<string, array{0: string, 1: DirectoryIdentity}> */
    private array $utenti = [];

    public bool $nonDisponibile = false;

    /** Elenco restituito da membriGruppi() (la sync). */
    /** @var list<DirectoryIdentity> */
    public array $membri = [];

    public bool $sync = true;

    /** @var list<string> gruppi configurati non trovati nell'ultimo membriGruppi() */
    public array $gruppiMancanti = [];

    public function gruppiMancantiUltimaLettura(): array
    {
        return $this->gruppiMancanti;
    }

    /** Lanciata da membriGruppi(): simula errori grezzi di ldap_search. */
    public ?\Throwable $errore = null;

    public ?string $motivo = null;

    /** Bind tentati: ognuno con password errata conterebbe verso il blocco account di AD. */
    public int $bind = 0;

    public function motivoUltimoRifiuto(): ?string
    {
        return $this->motivo;
    }

    /**
     * @param  list<string>  $groups
     * @param  string|null|false  $email  false = default "<username>@ente.local"; null = account AD senza email
     */
    public static function identita(string $username, array $groups, string|null|false $email = false, ?string $guid = null, bool $abilitato = true): DirectoryIdentity
    {
        return new DirectoryIdentity(
            guid: $guid ?? substr(md5($username), 0, 8).'-0000-0000-0000-'.substr(md5($username), 0, 12),
            username: $username,
            name: 'Utente '.$username,
            email: $email === false ? $username.'@ente.local' : $email,
            groups: $groups,
            abilitato: $abilitato,
        );
    }

    public function aggiungi(string $password, DirectoryIdentity $id): self
    {
        $this->utenti[mb_strtolower($id->username)] = [$password, $id];
        $this->membri = array_values(array_filter($this->membri, fn (DirectoryIdentity $m) => $m->guid !== $id->guid));
        $this->membri[] = $id;

        return $this;
    }

    public function syncConfigurata(): bool
    {
        return $this->sync;
    }

    public function membriGruppi(): array
    {
        if ($this->nonDisponibile || ! $this->sync) {
            throw new DirectoryNonDisponibile('Directory di test non disponibile');
        }
        if ($this->errore !== null) {
            throw $this->errore;
        }

        return array_values(array_filter($this->membri, fn (DirectoryIdentity $m) => $m->groups !== []));
    }

    public function authenticate(string $username, string $password): ?DirectoryIdentity
    {
        $this->bind++;

        if ($this->nonDisponibile) {
            throw new DirectoryNonDisponibile('Directory di test non disponibile');
        }

        $riga = $this->utenti[mb_strtolower($username)] ?? null;

        return ($riga !== null && $riga[0] === $password) ? $riga[1] : null;
    }
}
