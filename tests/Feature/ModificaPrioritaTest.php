<?php

namespace Tests\Feature;

use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/** L'operatore del comune corregge priorità e urgenza scelte dal segnalatore. */
class ModificaPrioritaTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true, 'name' => 'Anna Gestore']);
        $this->gestore->assignRole('gestore');
    }

    private function segnalazione(array $attr = []): Segnalazione
    {
        return Segnalazione::factory()->create(array_merge([
            'livello_priorita' => 4, 'id_stato_segnalazione' => 1,
        ], $attr));
    }

    public function test_gestore_abbassa_priorita(): void
    {
        $s = $this->segnalazione();

        $this->actingAs($this->gestore)
            ->patch(route('segnalazioni.priorita', $s), ['livello_priorita' => 2])
            ->assertRedirect(route('segnalazioni.show', $s->id_segnalazione))
            ->assertSessionHasNoErrors();

        $s->refresh();
        $this->assertSame(2, (int) $s->livello_priorita);

        $nota = $s->note()->latest('id_nota')->first();
        $this->assertNotNull($nota);
        $this->assertStringContainsString('Critica', $nota->testo);
        $this->assertStringContainsString('Media', $nota->testo);
        $this->assertFalse((bool) $nota->visibile_impresa);
        $this->assertSame($this->gestore->id, $nota->id_utente);
    }

    public function test_scadenza_sla_ricalcolata(): void
    {
        SlaConfigurazione::create(['livello_priorita' => 4, 'ore_target' => 4, 'ore_warning' => 1]);
        SlaConfigurazione::create(['livello_priorita' => 1, 'ore_target' => 240, 'ore_warning' => 24]);
        $s = $this->segnalazione(['data_segnalazione' => now()->subHours(10), 'sla_violato' => true]);

        $this->actingAs($this->gestore)
            ->patch(route('segnalazioni.priorita', $s), ['livello_priorita' => 1]);

        $s->refresh();
        $this->assertTrue($s->data_scadenza_sla->isFuture());
        $this->assertFalse((bool) $s->sla_violato);
    }

    public function test_nessuna_modifica_nessuna_nota(): void
    {
        $s = $this->segnalazione();

        $this->actingAs($this->gestore)
            ->patch(route('segnalazioni.priorita', $s), ['livello_priorita' => 4]);

        $this->assertSame(0, $s->note()->count());
    }

    public function test_valore_non_valido_rifiutato(): void
    {
        $s = $this->segnalazione();

        $this->actingAs($this->gestore)
            ->patch(route('segnalazioni.priorita', $s), ['livello_priorita' => 9])
            ->assertSessionHasErrors('livello_priorita');
        $this->assertSame(4, (int) $s->fresh()->livello_priorita);
    }

    public function test_segnalatore_operaio_e_impresa_non_possono(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Ditta']);
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $segnalatore = User::factory()->create();
        $segnalatore->assignRole('segnalatore');
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');
        $ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $ditta->assignRole('impresa');

        $s = $this->segnalazione([
            'id_utente_segnalazione' => $segnalatore->id, 'id_operatore_assegnato' => $operaio->id,
            'id_appalto' => $appalto->id_appalto, 'id_stato_segnalazione' => 4,
        ]);

        foreach ([$segnalatore, $operaio, $ditta] as $u) {
            $this->actingAs($u)
                ->patch(route('segnalazioni.priorita', $s), ['livello_priorita' => 1])
                ->assertForbidden();
        }
        $this->assertSame(4, (int) $s->fresh()->livello_priorita);
    }

    public function test_gestore_non_supervisore_solo_se_assegnato(): void
    {
        $gestore = User::factory()->create(['supervisore_segnalazioni' => false]);
        $gestore->assignRole('gestore');
        $altrui = $this->segnalazione();
        $sua = $this->segnalazione(['id_operatore_assegnato' => $gestore->id]);

        $this->actingAs($gestore)->patch(route('segnalazioni.priorita', $altrui), ['livello_priorita' => 1])->assertForbidden();
        $this->actingAs($gestore)->patch(route('segnalazioni.priorita', $sua), ['livello_priorita' => 1])->assertRedirect();
        $this->assertSame(1, (int) $sua->fresh()->livello_priorita);
    }

    public function test_assegna_fissa_anche_la_priorita(): void
    {
        SlaConfigurazione::create(['livello_priorita' => 4, 'ore_target' => 4, 'ore_warning' => 1]);
        SlaConfigurazione::create(['livello_priorita' => 1, 'ore_target' => 240, 'ore_warning' => 24]);
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');
        $s = $this->segnalazione(['id_stato_segnalazione' => 2, 'data_segnalazione' => now()->subHours(10), 'sla_violato' => true]);

        $this->actingAs($this->gestore)->post(route('segnalazioni.azione', $s), [
            'id_azione' => 2, 'id_operatore' => $operaio->id, 'livello_priorita' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect(route('segnalazioni.show', $s->id_segnalazione));

        $s->refresh();
        $this->assertSame(3, $s->statoEnum()->value);
        $this->assertSame($operaio->id, $s->id_operatore_assegnato);
        $this->assertSame(1, (int) $s->livello_priorita);
        $this->assertTrue($s->data_scadenza_sla->isFuture());
        $this->assertTrue($s->note()->where('testo', 'like', 'Priorità modificata:%')->exists());
    }

    public function test_assegna_senza_cambio_priorita_nessuna_nota(): void
    {
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');
        $s = $this->segnalazione(['id_stato_segnalazione' => 2]);

        $this->actingAs($this->gestore)->post(route('segnalazioni.azione', $s), [
            'id_azione' => 2, 'id_operatore' => $operaio->id, 'livello_priorita' => 4,
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, $s->fresh()->statoEnum()->value);
        $this->assertFalse($s->note()->where('testo', 'like', 'Priorità modificata:%')->exists());
    }

    public function test_assegna_priorita_non_valida_non_assegna(): void
    {
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');
        $s = $this->segnalazione(['id_stato_segnalazione' => 2]);

        $this->actingAs($this->gestore)->post(route('segnalazioni.azione', $s), [
            'id_azione' => 2, 'id_operatore' => $operaio->id, 'livello_priorita' => 9,
        ])->assertSessionHasErrors('livello_priorita');

        $this->assertSame(2, $s->fresh()->statoEnum()->value);
    }

    public function test_impresa_non_cambia_priorita_via_azione(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Ditta']);
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $ditta->assignRole('impresa');
        $s = $this->segnalazione(['id_appalto' => $appalto->id_appalto, 'id_stato_segnalazione' => 4]);

        $this->actingAs($ditta)->post(route('segnalazioni.azione', $s), [
            'id_azione' => 4, 'nota' => 'pausa', 'livello_priorita' => 1,
        ])->assertForbidden();

        $this->assertSame(4, (int) $s->fresh()->livello_priorita);
    }

    public function test_pannello_assegna_mostra_priorita(): void
    {
        $s = $this->segnalazione(['id_stato_segnalazione' => 2]);

        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $s))
            ->assertOk()->assertSee('Priorità…')->assertSee('Cambiandola si ricalcola la scadenza.');
    }

    public function test_scheda_mostra_modulo_solo_a_chi_puo(): void
    {
        $s = $this->segnalazione();
        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $s))
            ->assertOk()->assertSee(route('segnalazioni.priorita', $s), false);

        // La ditta vede la scheda (e ha `update`), ma la priorità non la tocca.
        $impresa = Impresa::create(['ragione_sociale' => 'Ditta']);
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $ditta->assignRole('impresa');
        $s->update(['id_appalto' => $appalto->id_appalto, 'id_stato_segnalazione' => 4]);
        $this->actingAs($ditta)->get(route('segnalazioni.show', $s))
            ->assertOk()->assertDontSee(route('segnalazioni.priorita', $s), false);
    }
}
