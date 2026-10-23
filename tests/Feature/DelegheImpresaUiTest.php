<?php

namespace Tests\Feature;

use App\Models\DelegaImpresa;
use App\Models\Impresa;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class DelegheImpresaUiTest extends TestCase
{
    use RefreshDatabase;

    private const CF = 'RSSMRA80A01H501U';

    private User $gestore;

    private Impresa $impresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->gestore = User::factory()->create(['auth_source' => 'ldap']);
        $this->gestore->assignRole('gestore');
        $this->impresa = Impresa::create(['ragione_sociale' => 'Edil Test']);
    }

    public function test_gestore_crea_delega(): void
    {
        $this->actingAs($this->gestore)
            ->post(route('imprese.deleghe.store', $this->impresa), ['codice_fiscale' => self::CF, 'motivo' => 'Appalto 2026'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $delega = DelegaImpresa::sole();
        $this->assertSame(DelegaImpresa::ATTIVA, $delega->stato);
        $this->assertSame($this->gestore->id, $delega->creata_da);
        $this->assertSame('Appalto 2026', $delega->motivo);
    }

    public function test_cf_gia_delegato_mostra_errore(): void
    {
        $this->actingAs($this->gestore)->post(route('imprese.deleghe.store', $this->impresa), ['codice_fiscale' => self::CF]);

        $this->actingAs($this->gestore)
            ->from(route('imprese.edit', $this->impresa))
            ->post(route('imprese.deleghe.store', $this->impresa), ['codice_fiscale' => self::CF])
            ->assertRedirect(route('imprese.edit', $this->impresa))
            ->assertSessionHasErrors('codice_fiscale');
        $this->assertSame(1, DelegaImpresa::count());
    }

    public function test_revoca(): void
    {
        $this->actingAs($this->gestore)->post(route('imprese.deleghe.store', $this->impresa), ['codice_fiscale' => self::CF]);
        $delega = DelegaImpresa::sole();

        $this->actingAs($this->gestore)->delete(route('imprese.deleghe.destroy', [$this->impresa, $delega]))->assertRedirect();

        $this->assertSame(DelegaImpresa::REVOCATA, $delega->fresh()->stato);
    }

    public function test_revoca_di_delega_di_altra_impresa_404(): void
    {
        $altra = Impresa::create(['ragione_sociale' => 'Altra']);
        $this->actingAs($this->gestore)->post(route('imprese.deleghe.store', $altra), ['codice_fiscale' => self::CF]);

        $this->actingAs($this->gestore)
            ->delete(route('imprese.deleghe.destroy', [$this->impresa, DelegaImpresa::sole()]))
            ->assertNotFound();
        $this->assertSame(DelegaImpresa::ATTIVA, DelegaImpresa::sole()->stato);
    }

    public function test_operaio_e_impresa_non_possono(): void
    {
        $this->actingAs($this->gestore)->post(route('imprese.deleghe.store', $this->impresa), ['codice_fiscale' => self::CF]);
        $delega = DelegaImpresa::sole();

        foreach (['operaio', 'impresa'] as $ruolo) {
            $u = User::factory()->create(['auth_source' => 'ldap']);
            $u->assignRole($ruolo);
            $this->actingAs($u)->post(route('imprese.deleghe.store', $this->impresa), ['codice_fiscale' => 'VRDLGU75B02H501K'])->assertForbidden();
            $this->actingAs($u)->delete(route('imprese.deleghe.destroy', [$this->impresa, $delega]))->assertForbidden();
            $this->actingAs($u)->post(route('imprese.disattiva', $this->impresa))->assertForbidden();
            $this->actingAs($u)->post(route('imprese.riattiva', $this->impresa))->assertForbidden();
        }
    }

    public function test_disattiva_e_riattiva(): void
    {
        $this->actingAs($this->gestore)->post(route('imprese.deleghe.store', $this->impresa), ['codice_fiscale' => self::CF]);

        $this->actingAs($this->gestore)->post(route('imprese.disattiva', $this->impresa))->assertRedirect();
        $this->assertFalse($this->impresa->fresh()->attiva);
        $this->assertSame(0, DelegaImpresa::attive()->count());

        $this->actingAs($this->gestore)->post(route('imprese.riattiva', $this->impresa))->assertRedirect();
        $this->assertTrue($this->impresa->fresh()->attiva);
    }

    public function test_pagina_modifica_mostra_persone_delegate(): void
    {
        $this->actingAs($this->gestore)->post(route('imprese.deleghe.store', $this->impresa), ['codice_fiscale' => self::CF]);

        $this->actingAs($this->gestore)->get(route('imprese.edit', $this->impresa))->assertOk()
            ->assertSee(self::CF)
            ->assertSee('in attesa del primo accesso')
            ->assertSee('Disattiva ditta');
    }

    public function test_impresa_con_deleghe_non_si_elimina(): void
    {
        $this->actingAs($this->gestore)->post(route('imprese.deleghe.store', $this->impresa), ['codice_fiscale' => self::CF]);

        $this->actingAs($this->gestore)->delete(route('imprese.destroy', $this->impresa))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertNotNull($this->impresa->fresh());
    }

    public function test_elenco_mostra_badge_disattivata(): void
    {
        $this->impresa->update(['attiva' => false]);

        $this->actingAs($this->gestore)->get(route('imprese.index'))->assertOk()->assertSee('Disattivata');
    }
}
