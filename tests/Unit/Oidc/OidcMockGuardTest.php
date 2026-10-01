<?php

namespace Tests\Unit\Oidc;

use App\Services\Oidc\OidcMockGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OidcMockGuardTest extends TestCase
{
    public function test_mock_in_produzione_lancia_eccezione(): void
    {
        $this->expectException(RuntimeException::class);
        OidcMockGuard::verifica('production', true);
    }

    public function test_mock_ammesso_fuori_produzione_e_disattivo_ovunque(): void
    {
        OidcMockGuard::verifica('local', true);
        OidcMockGuard::verifica('production', false);
        $this->addToAssertionCount(1);
    }
}
