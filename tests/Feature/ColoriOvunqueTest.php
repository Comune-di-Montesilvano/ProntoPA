<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\Segnalazione;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use App\Support\Segnalazioni\Fatti;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 1.0.4: stessi colori di stati, tipologie e priorità in apertura, scheda e statistiche. */
class ColoriOvunqueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->admin = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->admin->assignRole('admin');
    }

    private function idrico(): int
    {
        return (int) TipologiaSegnalazione::where('descrizione', 'IMPIANTO IDRICO')->value('id_tipologia_segnalazione');
    }

    public function test_nuova_segnalazione_riquadri_e_priorita_a_colori(): void
    {
        $this->actingAs($this->admin)->get(route('segnalazioni.create'))
            ->assertOk()
            ->assertSee('pa-tono-verde', false)
            ->assertSee('Verde e Giardini')
            ->assertSee('pa-priorita-4', false);
    }

    public function test_scheda_mostra_la_tipologia_nel_suo_colore(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_tipologia_segnalazione' => $this->idrico()]);

        $this->actingAs($this->admin)->get(route('segnalazioni.show', $s->id_segnalazione))
            ->assertOk()
            ->assertSee('pa-tipologia pa-tono-blu', false)
            ->assertSee('Impianto Idrico');
    }

    public function test_fatti_usano_i_colori_della_tavolozza(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => SegnalazioneStato::NUOVA->value, 'livello_priorita' => 4]);

        $fatti = Fatti::per($s->fresh());
        $this->assertSame('#ea580c', $fatti['stato']['colore']);
        $this->assertSame('#dc2626', $fatti['priorita']['colore']);
    }

    public function test_statistiche_coi_colori_di_tipologia_e_stato(): void
    {
        Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_tipologia_segnalazione' => $this->idrico()]);

        $this->actingAs($this->admin)->get(route('statistiche.index'))
            ->assertOk()
            ->assertViewHas('tipologiaColori', ['#2563eb'])
            ->assertViewHas('statoColori', ['#ea580c']);
    }
}
