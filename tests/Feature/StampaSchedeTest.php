<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Stampa di tutte le schede dell'elenco filtrato (una per pagina) e filtro "Nuove". */
class StampaSchedeTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
    }

    public function test_tab_nuove_mostra_solo_stato_nuova(): void
    {
        $nuova = Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'testo_segnalazione' => 'Rubinetto nuovo']);
        Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'testo_segnalazione' => 'Porta in carico']);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['tab' => 'nuove']))
            ->assertOk()
            ->assertSee('Rubinetto nuovo')
            ->assertDontSee('Porta in carico')
            ->assertViewHas('conteggi', fn ($c) => $c['nuove'] === 1)
            ->assertSee(route('gestione.stampa-schede', ['tab' => 'nuove']), false);
        $this->assertNotNull($nuova);
    }

    public function test_stampa_schede_una_per_pagina_rispettando_filtri(): void
    {
        $a = Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_tipologia_segnalazione' => 1]);
        $b = Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_tipologia_segnalazione' => 1]);
        $altraTipologia = Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_tipologia_segnalazione' => 2]);
        $inCarico = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'id_tipologia_segnalazione' => 1]);

        $html = $this->actingAs($this->gestore)
            ->get(route('gestione.stampa-schede', ['tab' => 'nuove', 'id_tipologia' => 1]))
            ->assertOk()
            ->assertSee('Scheda Segnalazione #'.$a->id_segnalazione)
            ->assertSee('Scheda Segnalazione #'.$b->id_segnalazione)
            ->assertDontSee('Scheda Segnalazione #'.$altraTipologia->id_segnalazione)
            ->assertDontSee('Scheda Segnalazione #'.$inCarico->id_segnalazione)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'class="scheda"'));
        $this->assertStringContainsString('page-break-after', $html);
    }

    public function test_gestore_non_supervisore_stampa_solo_le_sue(): void
    {
        $gestore = User::factory()->create(['supervisore_segnalazioni' => false]);
        $gestore->assignRole('gestore');
        $altrui = Segnalazione::factory()->create(['id_stato_segnalazione' => 1]);

        $this->actingAs($gestore)->get(route('gestione.stampa-schede', ['tab' => 'nuove']))
            ->assertOk()
            ->assertDontSee('Scheda Segnalazione #'.$altrui->id_segnalazione);
    }

    public function test_scheda_singola_invariata(): void
    {
        $s = Segnalazione::factory()->create(['testo_segnalazione' => 'Finestra rotta aula 3']);

        $this->actingAs($this->gestore)->get(route('segnalazioni.stampa', $s))
            ->assertOk()
            ->assertSee('Scheda Segnalazione #'.$s->id_segnalazione)
            ->assertSee('Finestra rotta aula 3')
            ->assertSee('window.print()', false);
    }

    public function test_stampa_lista_applica_tutti_i_filtri(): void
    {
        $vecchia = Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'testo_segnalazione' => 'Vecchia infiltrazione']);
        $vecchia->forceFill(['data_segnalazione' => now()->subYear()])->saveQuietly();
        Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'testo_segnalazione' => 'Recente infiltrazione']);

        $this->actingAs($this->gestore)
            ->get(route('gestione.stampa', ['tab' => 'nuove', 'data_da' => now()->subMonth()->toDateString()]))
            ->assertOk()
            ->assertSee('Recente infiltrazione')
            ->assertDontSee('Vecchia infiltrazione');
    }

    public function test_elenco_vuoto_messaggio(): void
    {
        $this->actingAs($this->gestore)->get(route('gestione.stampa-schede', ['tab' => 'nuove']))
            ->assertOk()
            ->assertSee('Nessuna segnalazione da stampare');
    }

    public function test_operaio_non_accede(): void
    {
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');

        $this->actingAs($operaio)->get(route('gestione.stampa-schede'))->assertForbidden();
    }
}
