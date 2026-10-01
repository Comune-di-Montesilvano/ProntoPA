<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersSpidTest extends TestCase
{
    use RefreshDatabase;

    public function test_codice_fiscale_unico(): void
    {
        User::factory()->create(['auth_source' => 'spid', 'codice_fiscale' => 'RSSMRA80A01G482X', 'password' => null]);

        $this->expectException(QueryException::class);
        User::factory()->create(['auth_source' => 'spid', 'codice_fiscale' => 'RSSMRA80A01G482X', 'password' => null]);
    }

    public function test_helper_spid_e_blocco(): void
    {
        $spid = User::factory()->create(['auth_source' => 'spid', 'codice_fiscale' => 'RSSMRA80A01G482X', 'password' => null]);
        $locale = User::factory()->create();

        $this->assertTrue($spid->isSpid());
        $this->assertFalse($locale->isSpid());
        $this->assertFalse($spid->isBloccato());

        $spid->forceFill(['bloccato_at' => now(), 'motivo_blocco' => 'segnalato dalla segreteria'])->save();
        $this->assertTrue($spid->fresh()->isBloccato());
    }
}
