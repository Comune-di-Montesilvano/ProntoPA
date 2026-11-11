<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Directory\Directory;
use App\Services\Directory\MockDirectory;
use App\Services\Directory\NullDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ldap:prova --sync: verifica l'account di servizio e conta i membri, senza scrivere. */
class ProvaLdapSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_mock_conta_membri_senza_scrivere(): void
    {
        $this->app->instance(Directory::class, new MockDirectory());
        config(['ldap.sync.username' => 'svc@ente.local', 'ldap.sync.password' => 'segretissima']);
        $prima = User::count();

        $this->artisan('ldap:prova --sync')
            ->expectsOutputToContain('PRONTOPA_GESTORI')
            ->expectsOutputToContain('6 membri')
            ->doesntExpectOutputToContain('segretissima')
            ->assertSuccessful();

        $this->assertSame($prima, User::count());
    }

    public function test_sync_senza_directory_fallisce(): void
    {
        $this->app->instance(Directory::class, new NullDirectory());

        $this->artisan('ldap:prova --sync')
            ->expectsOutputToContain('non configurat')
            ->assertFailed();
    }
}
