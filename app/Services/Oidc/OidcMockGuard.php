<?php

namespace App\Services\Oidc;

use RuntimeException;

final class OidcMockGuard
{
    public static function verifica(string $ambiente, bool $mock): void
    {
        if ($ambiente === 'production' && $mock) {
            throw new RuntimeException('OIDC_MOCK=true non è ammesso in produzione.');
        }
    }
}
