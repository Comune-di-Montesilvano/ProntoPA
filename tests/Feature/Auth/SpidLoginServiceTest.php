<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\AccessoSpidNegato;
use App\Services\Auth\SpidLoginService;
use App\Services\Oidc\IdentitaSpid;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpidLoginServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function identita(string $sub = 'sub-1', ?string $email = 'm.rossi@example.it'): IdentitaSpid
    {
        return new IdentitaSpid('RSSMRA80A01G482X', 'Mario', 'Rossi', $email, $sub);
    }

    private function service(): SpidLoginService
    {
        return app(SpidLoginService::class);
    }

    public function test_cf_nuovo_restituisce_null(): void
    {
        $this->assertNull($this->service()->accedi($this->identita()));
        $this->assertSame(0, User::count());
    }

    public function test_crea_da_profilo(): void
    {
        $user = $this->service()->creaDaProfilo($this->identita(), 'Mario.Rossi@Scuola.it');

        $this->assertSame('spid', $user->auth_source);
        $this->assertSame('RSSMRA80A01G482X', $user->codice_fiscale);
        $this->assertSame('mario.rossi@scuola.it', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->password);
        $this->assertSame('Mario Rossi', $user->name);
        $this->assertSame('RSSMRA80A01G482X', $user->username);
        $this->assertSame(2, (int) $user->id_provenienza);
        $this->assertTrue($user->hasRole('segnalatore'));
    }

    public function test_username_e_il_codice_fiscale_anche_con_omonimi(): void
    {
        User::factory()->create(['username' => 'mario.rossi']);

        $user = $this->service()->creaDaProfilo($this->identita(), 'a@b.it');

        $this->assertSame('RSSMRA80A01G482X', $user->username);
    }

    public function test_stesso_cf_con_sub_diverso_ritrova_lo_stesso_utente(): void
    {
        $creato = $this->service()->creaDaProfilo($this->identita('sub-spid'), 'a@b.it');

        $ritrovato = $this->service()->accedi($this->identita('sub-cie'));

        $this->assertSame($creato->id, $ritrovato->id);
        $this->assertSame('sub-cie', $ritrovato->fresh()->oidc_subject);
        $this->assertNotNull($ritrovato->fresh()->last_login);
    }

    public function test_crea_da_profilo_due_volte_non_duplica(): void
    {
        $primo = $this->service()->creaDaProfilo($this->identita(), 'a@b.it');
        $secondo = $this->service()->creaDaProfilo($this->identita(), 'c@d.it');

        $this->assertSame($primo->id, $secondo->id);
        $this->assertSame(1, User::where('codice_fiscale', 'RSSMRA80A01G482X')->count());
    }

    public function test_utente_bloccato_o_disattivato_nega_accesso(): void
    {
        $user = $this->service()->creaDaProfilo($this->identita(), 'a@b.it');
        $user->forceFill(['bloccato_at' => now()])->save();

        try {
            $this->service()->accedi($this->identita());
            $this->fail('utente bloccato ammesso');
        } catch (AccessoSpidNegato) {
        }

        $user->forceFill(['bloccato_at' => null, 'attivo' => false])->save();
        $this->expectException(AccessoSpidNegato::class);
        $this->service()->accedi($this->identita());
    }
}
