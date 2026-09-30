<?php

namespace Tests\Unit\Directory;

use App\Services\Directory\DirectoryNonDisponibile;
use App\Services\Directory\LdapRecordDirectory;
use PHPUnit\Framework\TestCase;

class LdapRecordDirectoryTest extends TestCase
{
    private function config(string $host): array
    {
        return ['host' => $host, 'port' => 389, 'base_dn' => 'DC=x',
            'user_dn_template' => '%s', 'starttls' => false, 'tls_skip_verify' => false, 'timeout' => 1];
    }

    public function test_endpoint_da_url_ldap_con_porta(): void
    {
        $this->assertSame(
            ['host' => 'dc.ente.local', 'port' => 3268, 'ssl' => false],
            LdapRecordDirectory::endpoint('ldap://dc.ente.local:3268', 389),
        );
    }

    public function test_endpoint_ldaps_senza_porta_usa_636(): void
    {
        $this->assertSame(
            ['host' => 'dc.ente.local', 'port' => 636, 'ssl' => true],
            LdapRecordDirectory::endpoint('ldaps://dc.ente.local', 389),
        );
    }

    public function test_endpoint_host_nudo_usa_porta_di_default(): void
    {
        $this->assertSame(
            ['host' => '10.0.0.5', 'port' => 389, 'ssl' => false],
            LdapRecordDirectory::endpoint('10.0.0.5', 389),
        );
    }

    public function test_password_vuota_non_contatta_il_server(): void
    {
        $dir = new LdapRecordDirectory($this->config('ldap://host.inesistente.invalid'));

        $this->assertNull($dir->authenticate('qualcuno', ''));
    }

    public function test_solo_errore_49_e_credenziale_non_valida(): void
    {
        $this->assertTrue(LdapRecordDirectory::credenzialiNonValide(49));
        $this->assertFalse(LdapRecordDirectory::credenzialiNonValide(-1));
        $this->assertFalse(LdapRecordDirectory::credenzialiNonValide(81));
    }

    public function test_host_irraggiungibile_lancia_non_disponibile(): void
    {
        $dir = new LdapRecordDirectory($this->config('ldap://127.0.0.1:1'));

        $this->expectException(DirectoryNonDisponibile::class);
        $dir->authenticate('qualcuno', 'password');
    }
}
