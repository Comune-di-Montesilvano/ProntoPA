<?php

namespace Tests\Unit\Directory;

use App\Services\Directory\MockDirectory;
use PHPUnit\Framework\TestCase;

class MockDirectoryTest extends TestCase
{
    public function test_credenziali_mock_valide_restituiscono_identita_con_gruppo(): void
    {
        $id = (new MockDirectory())->authenticate('mock.gestore', 'mock.gestore');

        $this->assertNotNull($id);
        $this->assertSame('mock.gestore', $id->username);
        $this->assertSame('mock.gestore@mock.local', $id->email);
        $this->assertSame(['PRONTOPA_GESTORI'], $id->groups);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id->guid);
    }

    public function test_guid_stabile_tra_login(): void
    {
        $dir = new MockDirectory();

        $this->assertSame(
            $dir->authenticate('mock.admin', 'mock.admin')->guid,
            $dir->authenticate('MOCK.ADMIN', 'mock.admin')->guid,
        );
    }

    public function test_password_errata_o_utente_ignoto_restituiscono_null(): void
    {
        $dir = new MockDirectory();

        $this->assertNull($dir->authenticate('mock.admin', 'sbagliata'));
        $this->assertNull($dir->authenticate('admin', 'admin'));
        $this->assertNull($dir->authenticate('sconosciuto', 'sconosciuto'));
    }

    public function test_utente_senza_gruppi(): void
    {
        $this->assertSame([], (new MockDirectory())->authenticate('mock.nessungruppo', 'mock.nessungruppo')->groups);
    }
}
