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

    public function test_gruppo_spezzato_tra_pagine_continua(): void
    {
        $alfa = $this->sede('ALFA');
        Segnalazione::factory()->count(31)->create(['id_stato_segnalazione' => 1, 'id_plesso' => $alfa->id_plesso]);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['gruppo' => 'sede', 'page' => 2]))
            ->assertOk()
            ->assertSee('(continua)')
            ->assertSee('31 · 0 in ritardo');
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
