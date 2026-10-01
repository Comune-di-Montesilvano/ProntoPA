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

    /**
     * @param  list<string>  $groups
     * @param  string|null|false  $email  false = default "<username>@ente.local"; null = account AD senza email
     */
    public static function identita(string $username, array $groups, string|null|false $email = false, ?string $guid = null): DirectoryIdentity
    {
        return new DirectoryIdentity(
            guid: $guid ?? substr(md5($username), 0, 8).'-0000-0000-0000-'.substr(md5($username), 0, 12),
            username: $username,
            name: 'Utente '.$username,
            email: $email === false ? $username.'@ente.local' : $email,
            groups: $groups,
        );
    }

    public function aggiungi(string $password, DirectoryIdentity $id): self
    {
        $this->utenti[mb_strtolower($id->username)] = [$password, $id];

        return $this;
    }

    public function authenticate(string $username, string $password): ?DirectoryIdentity
    {
        if ($this->nonDisponibile) {
            throw new DirectoryNonDisponibile('Directory di test non disponibile');
        }

        $riga = $this->utenti[mb_strtolower($username)] ?? null;

        return ($riga !== null && $riga[0] === $password) ? $riga[1] : null;
    }
}
