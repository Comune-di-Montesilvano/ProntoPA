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

    /** Committente: la paginazione non spezza i gruppi di primo livello. */
    public function test_pagine_con_gruppi_interi(): void
    {
        $alfa = $this->sede('ALFA');
        $beta = $this->sede('BETA');
        $gamma = $this->sede('GAMMA');
        Segnalazione::factory()->count(20)->create(['id_stato_segnalazione' => 1, 'id_plesso' => $alfa->id_plesso, 'titolo' => 'Guasto alfa']);
        Segnalazione::factory()->count(15)->create(['id_stato_segnalazione' => 1, 'id_plesso' => $beta->id_plesso, 'titolo' => 'Guasto beta']);
        Segnalazione::factory()->count(40)->create(['id_stato_segnalazione' => 1, 'id_plesso' => $gamma->id_plesso, 'titolo' => 'Guasto gamma']);

        $pagina1 = $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['gruppo' => 'sede']))->assertOk();
        $this->assertSame(20, substr_count($pagina1->getContent(), 'Guasto alfa'));
        $this->assertSame(0, substr_count($pagina1->getContent(), 'Guasto beta'));

        $pagina2 = $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['gruppo' => 'sede', 'page' => 2]))->assertOk();
        $this->assertSame(15, substr_count($pagina2->getContent(), 'Guasto beta'));
        $this->assertSame(0, substr_count($pagina2->getContent(), 'Guasto gamma'));

        $pagina3 = $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['gruppo' => 'sede', 'page' => 3]))->assertOk();
        $this->assertSame(40, substr_count($pagina3->getContent(), 'Guasto gamma'), 'gruppo grande intero anche oltre 30');
        $pagina3->assertSee('40 · 0 in ritardo')->assertDontSee('(continua)');
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
}
