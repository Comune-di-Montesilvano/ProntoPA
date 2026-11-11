<?php

namespace App\Services\Directory;

final class DirectoryIdentity
{
    /**
     * @param  list<string>  $groups  CN dei gruppi di appartenenza (anche annidati)
     * @param  bool  $abilitato  false = account disabilitato in AD (userAccountControl 0x2)
     */
    public function __construct(
        public readonly string $guid,
        public readonly string $username,
        public readonly string $name,
        public readonly ?string $email,
        public readonly array $groups,
        public readonly bool $abilitato = true,
    ) {}
}
