<?php

namespace Tests\Feature\Auth;

use App\Services\Directory\DirectoryNonDisponibile;
use App\Services\Directory\LdapRecordDirectory;
use App\Services\Directory\MockDirectory;
use App\Services\Directory\NullDirectory;
use Tests\TestCase;

/** Elenco dei membri dei gruppi PRONTOPA_* letto con l'account di servizio. */
class DirectoryMembriTest extends TestCase
{
    public function test_mock_elenca_i_membri_dei_gruppi(): void
    {
        $membri = (new MockDirectory())->membriGruppi();

        $this->assertCount(6, $membri);
        $perUsername = collect($membri)->keyBy('username');
        $this->assertFalse($perUsername->has('mock.nessungruppo'));
        $this->assertSame(['PRONTOPA_GESTORI'], $perUsername['mock.gestore']->groups);
        $this->assertTrue($perUsername['mock.gestore']->abilitato);
        $this->assertTrue((new MockDirectory())->syncConfigurata());
    }

    public function test_null_directory_non_sincronizza(): void
    {
        $this->assertFalse((new NullDirectory())->syncConfigurata());
        $this->expectException(DirectoryNonDisponibile::class);
        (new NullDirectory())->membriGruppi();
    }

    public function test_ldap_senza_credenziali_di_sync_non_sincronizza(): void
    {
        $dir = new LdapRecordDirectory(array_merge((array) config('ldap'), [
            'host' => 'ad.ente.local', 'sync' => ['username' => '', 'password' => 'x'],
        ]));

        $this->assertFalse($dir->syncConfigurata());
        $this->expectException(DirectoryNonDisponibile::class);
        $dir->membriGruppi();
    }

    public function test_ldap_con_credenziali_di_sync_configurata(): void
    {
        $dir = new LdapRecordDirectory(array_merge((array) config('ldap'), [
            'host' => 'ad.ente.local', 'sync' => ['username' => 'svc@ente.local', 'password' => 'x'],
        ]));

        $this->assertTrue($dir->syncConfigurata());
    }

    public function test_gruppo_configurato_non_trovato_e_un_errore(): void
    {
        $this->assertSame(['PRONTOPA_URP'], LdapRecordDirectory::gruppiMancanti(
            ['PRONTOPA_GESTORI', ' prontopa_urp ', ''], ['prontopa_gestori']));
        $this->assertSame([], LdapRecordDirectory::gruppiMancanti(['PRONTOPA_GESTORI', ''], ['PRONTOPA_GESTORI']));
    }

    public function test_bit_account_disabilitato(): void
    {
        $this->assertTrue(LdapRecordDirectory::disabilitato('514'));
        $this->assertFalse(LdapRecordDirectory::disabilitato('512'));
        $this->assertFalse(LdapRecordDirectory::disabilitato(null));
    }
}
