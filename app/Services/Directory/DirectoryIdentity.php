<?php

namespace App\Services\Directory;

final class DirectoryIdentity
{
    /**
     * @param  list<string>  $groups  CN dei gruppi di appartenenza (anche annidati)
     */
    public function __construct(
        public readonly string $guid,
        public readonly string $username,
        public readonly string $name,
        public readonly ?string $email,
        public readonly array $groups,
    ) {}
}
