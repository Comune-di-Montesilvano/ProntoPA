<?php

namespace Tests\Unit\Directory;

use App\Services\Directory\LdapConfigGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LdapConfigGuardTest extends TestCase
{
    public function test_mock_in_produzione_lancia_eccezione(): void
    {
        $this->expectException(RuntimeException::class);

        LdapConfigGuard::verifica('production', 'mock');
    }

    public function test_mock_fuori_produzione_e_ammesso(): void
    {
        LdapConfigGuard::verifica('local', 'mock');
        LdapConfigGuard::verifica('testing', 'mock');
        LdapConfigGuard::verifica('production', 'ldap://dc.ente.local');
        LdapConfigGuard::verifica('production', null);

        $this->addToAssertionCount(1);
    }

    public function test_avviso_tls_senza_verifica_solo_in_produzione(): void
    {
        $this->assertNotNull(LdapConfigGuard::avviso('production', true));
        $this->assertNull(LdapConfigGuard::avviso('production', false));
        $this->assertNull(LdapConfigGuard::avviso('local', true));
    }
}
