<?php

namespace App\Services\Auth;

final class RuoloLdap
{
    public function __construct(
        public readonly string $ruolo,
        public readonly bool $supervisore,
        public readonly bool $perConto,
        public readonly int $idProvenienza,
    ) {}
}
