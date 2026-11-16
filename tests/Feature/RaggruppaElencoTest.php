<?php

namespace Tests\Feature;

use App\Models\CategoriaEnte;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 1.0.4: "Raggruppa per" nell'elenco gestione. */
class RaggruppaElencoTest extends TestCase
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

    private function sede(string $nome): Plesso
    {
        $ente = Istituto::create(['descrizione' => 'IC '.$nome, 'id_categoria' => CategoriaEnte::factory()->create()->id_categoria, 'attivo' => true]);

        return Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => $nome, 'indirizzo' => 'Via '.$nome]);
    }

    private function tipologia(string $descrizione): int
    {
        return (int) TipologiaSegnalazione::where('descrizione', $descrizione)->value('id_tipologia_segnalazione');
    }

    public function test_raggruppa_per_sede_e_tipologia(): void
    {
        $zeta = $this->sede('ZETA');
        $alfa = $this->sede('ALFA');
        $idrico = $this->tipologia('IMPIANTO IDRICO');
        $elettrico = $this->tipologia('IMPIANTO ELETTRICO');
        Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_plesso' => $zeta->id_plesso, 'id_tipologia_segnalazione' => $idrico, 'titolo' => 'Rubinetto zeta']);
        Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_plesso' => $alfa->id_plesso, 'id_tipologia_segnalazione' => $idrico, 'titolo' => 'Rubinetto alfa',
            'data_scadenza_sla' => now()->subDays(3)]);
        Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_plesso' => $alfa->id_plesso, 'id_tipologia_segnalazione' => $elettrico, 'titolo' => 'Luce alfa']);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['gruppo' => 'sede_tipologia']))
            ->assertOk()
            ->assertSee('rowspan="2"', false)
            ->assertSeeInOrder(['Alfa', 'Impianto Elettrico', 'Luce alfa', 'Impianto Idrico', 'Rubinetto alfa', 'Zeta', 'Rubinetto zeta'])
            ->assertSee('2 · 1 in ritardo')
            ->assertSee('<option value="sede_tipologia" selected', false);
    }

    public function test_raggruppa_per_tipologia_mette_la_sede_in_riga(): void
    {
        $alfa = $this->sede('ALFA');
        Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_plesso' => $alfa->id_plesso, 'id_tipologia_segnalazione' => $this->tipologia('IMPIANTO IDRICO')]);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['gruppo' => 'tipologia']))
            ->assertOk()
            ->assertSeeInOrder(['>Tipologia<', '>Sede<', '>Stato<'], false)
            ->assertSee('1 · 0 in ritardo', false);
    }

    /** Committente: la paginazione non spezza i gruppi di primo livello; pagine fino a ~100 righe. */
    public function test_pagine_con_gruppi_interi(): void
    {
        $alfa = $this->sede('ALFA');
        $beta = $this->sede('BETA');
        $gamma = $this->sede('GAMMA');
        Segnalazione::factory()->count(60)->create(['id_stato_segnalazione' => 1, 'id_plesso' => $alfa->id_plesso, 'titolo' => 'Guasto alfa']);
        Segnalazione::factory()->count(30)->create(['id_stato_segnalazione' => 1, 'id_plesso' => $beta->id_plesso, 'titolo' => 'Guasto beta']);
        Segnalazione::factory()->count(20)->create(['id_stato_segnalazione' => 1, 'id_plesso' => $gamma->id_plesso, 'titolo' => 'Guasto gamma']);

        $righe = function (int $pagina, string $titolo): int {
            $html = $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['gruppo' => 'sede', 'page' => $pagina]))->assertOk()->getContent();
            $corpo = substr($html, strpos($html, '<tbody>'), strpos($html, '</tbody>') - strpos($html, '<tbody>'));

            return substr_count($corpo, $titolo);
        };

        $this->assertSame(60, $righe(1, 'Guasto alfa'));
        $this->assertSame(30, $righe(1, 'Guasto beta'));
        $this->assertSame(0, $righe(1, 'Guasto gamma'), '60+30+20 supera 100: gamma va intera nella pagina dopo');
        $this->assertSame(20, $righe(2, 'Guasto gamma'));

        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['gruppo' => 'sede', 'page' => 2]))
            ->assertSee('20 · 0 in ritardo')->assertDontSee('(continua)');
    }

    public function test_gruppo_sconosciuto_e_chiuse(): void
    {
        Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'titolo' => 'Senza gruppo']);
        Segnalazione::factory()->create(['id_stato_segnalazione' => 7, 'data_chiusura' => now(), 'titolo' => 'Chiusa ieri']);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['gruppo' => 'fucsia']))
            ->assertOk()->assertSee('Senza gruppo')->assertDontSee('pa-cella-gruppo', false);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['tab' => 'chiuse', 'gruppo' => 'sede']))
            ->assertOk()->assertSee('Chiusa ieri')->assertSee('pa-cella-gruppo', false);
    }

    /** Committente: di default sede poi tipologia; la scelta resta all'utente (anche su un altro dispositivo). */
    public function test_predefinito_sede_tipologia_e_scelta_ricordata(): void
    {
        Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_plesso' => $this->sede('ALFA')->id_plesso]);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard'))
            ->assertOk()->assertSee('<option value="sede_tipologia" selected', false)->assertSee('pa-cella-gruppo', false);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['gruppo' => 'tipologia']))->assertOk();
        $this->assertSame('tipologia', $this->gestore->fresh()->gruppo_elenco);
        $this->actingAs($this->gestore->fresh())->get(route('gestione.dashboard'))
            ->assertOk()->assertSee('<option value="tipologia" selected', false);

        $this->actingAs($this->gestore->fresh())->get(route('gestione.dashboard', ['gruppo' => '']))->assertOk();
        $this->assertSame('', $this->gestore->fresh()->gruppo_elenco);
        $this->actingAs($this->gestore->fresh())->get(route('gestione.dashboard'))
            ->assertOk()->assertSee('<option value="" selected', false)->assertDontSee('pa-cella-gruppo', false);

        $this->actingAs($this->gestore->fresh())->get(route('gestione.dashboard', ['gruppo' => 'fucsia']))->assertOk();
        $this->assertSame('', $this->gestore->fresh()->gruppo_elenco, 'valore sconosciuto non salvato');
    }
}
