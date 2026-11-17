<?php

namespace Tests\Feature;

use App\Models\CategoriaEnte;
use App\Models\Istituto;
use App\Models\NotaSegnalazione;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 1.0.6: anteprima in sola lettura al passaggio del mouse sull'elenco, filtrata come la scheda. */
class AnteprimaSegnalazioneTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $segnalatore;

    private Segnalazione $segnalazione;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);

        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $this->segnalatore = User::factory()->create(['name' => 'Maria Bianchi']);
        $this->segnalatore->assignRole('segnalatore');

        $ente = Istituto::create(['descrizione' => 'IC ALFA', 'id_categoria' => CategoriaEnte::factory()->create()->id_categoria, 'attivo' => true]);
        $sede = Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => 'PLESSO ALFA', 'indirizzo' => 'Via Roma 1']);

        $this->segnalazione = Segnalazione::factory()->create([
            'id_stato_segnalazione' => 1,
            'id_plesso' => $sede->id_plesso,
            'id_utente_segnalazione' => $this->segnalatore->id,
            'titolo' => 'Rubinetto rotto',
            'testo_segnalazione' => 'Il rubinetto del bagno al primo piano perde acqua da ieri.',
        ]);
        NotaSegnalazione::create([
            'id_segnalazione' => $this->segnalazione->id_segnalazione, 'id_utente' => $this->gestore->id,
            'testo' => 'Nota riservata al comune', 'data' => now(), 'visibile_web' => false, 'visibile_impresa' => false,
        ]);
    }

    private function anteprima(User $chi)
    {
        return $this->actingAs($chi)->get(route('segnalazioni.anteprima', $this->segnalazione->id_segnalazione));
    }

    public function test_gestore_vede_testo_sede_segnalante_e_ultima_nota(): void
    {
        $this->anteprima($this->gestore)->assertOk()
            ->assertSee('Il rubinetto del bagno al primo piano perde acqua da ieri.')
            ->assertSee('Plesso Alfa')->assertSee('Via Roma 1')
            ->assertSee('Maria Bianchi')
            ->assertSee('Nota riservata al comune')
            ->assertDontSee('<html', false);
    }

    public function test_segnalante_non_vede_note_interne(): void
    {
        $this->anteprima($this->segnalatore)->assertOk()
            ->assertSee('Il rubinetto del bagno al primo piano perde acqua da ieri.')
            ->assertDontSee('Nota riservata al comune');
    }

    public function test_chi_non_vede_la_segnalazione_non_vede_anteprima(): void
    {
        $altro = User::factory()->create();
        $altro->assignRole('segnalatore');

        $this->anteprima($altro)->assertForbidden();
    }

    public function test_elenco_gestione_porta_il_link_all_anteprima(): void
    {
        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['gruppo' => '']))->assertOk()
            ->assertSee('data-anteprima="'.route('segnalazioni.anteprima', $this->segnalazione->id_segnalazione).'"', false);
    }
}
