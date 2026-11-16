<?php

namespace Tests\Feature;

use App\Models\AdesioneSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Elenco gestore: più urgenti prima, filtri rapidi, filtri conservati cambiando vista. */
class ElencoGestioneTest extends TestCase
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

    public function test_ordine_urgenti_scadute_poi_in_scadenza_poi_senza(): void
    {
        $senza = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'data_scadenza_sla' => null, 'testo_segnalazione' => 'Senza scadenza']);
        $tra = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'data_scadenza_sla' => now()->addDays(3), 'testo_segnalazione' => 'Tra tre giorni']);
        $scaduta = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'data_scadenza_sla' => now()->subDays(2), 'testo_segnalazione' => 'Scaduta ieri']);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Scaduta ieri', 'Tra tre giorni', 'Senza scadenza'])
            ->assertViewHas('segnalazioni', fn ($p) => $p->pluck('id_segnalazione')->all() === [$scaduta->id_segnalazione, $tra->id_segnalazione, $senza->id_segnalazione]);
    }

    public function test_ordine_recenti(): void
    {
        $vecchia = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'data_segnalazione' => now()->subDays(10), 'data_scadenza_sla' => now()->subDay()]);
        $recente = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'data_segnalazione' => now()->subDay(), 'data_scadenza_sla' => null]);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['ordine' => 'recenti']))
            ->assertViewHas('segnalazioni', fn ($p) => $p->pluck('id_segnalazione')->all() === [$recente->id_segnalazione, $vecchia->id_segnalazione]);
    }

    public function test_filtro_scadute(): void
    {
        Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'data_scadenza_sla' => now()->addDays(3), 'testo_segnalazione' => 'Ancora in tempo']);
        Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'data_scadenza_sla' => now()->subHours(5), 'testo_segnalazione' => 'Tempo superato']);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['filtro' => 'scadute']))
            ->assertOk()
            ->assertSee('Tempo superato')
            ->assertDontSee('Ancora in tempo')
            ->assertViewHas('conteggi', fn ($c) => $c['scadute'] === 1 && $c['entro24h'] === 0);
    }

    public function test_filtro_sollecitate(): void
    {
        $sollecitata = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'testo_segnalazione' => 'Segnalata più volte']);
        Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'testo_segnalazione' => 'Segnalata una volta']);
        AdesioneSegnalazione::create(['id_segnalazione' => $sollecitata->id_segnalazione, 'id_utente' => $this->gestore->id]);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['filtro' => 'sollecitate']))
            ->assertOk()
            ->assertSee('Segnalata più volte')
            ->assertDontSee('Segnalata una volta');
    }

    public function test_filtro_ricorrenti(): void
    {
        $precedente = Segnalazione::factory()->create(['id_stato_segnalazione' => 7, 'data_chiusura' => now()->subMonth(), 'testo_segnalazione' => 'Riparata a settembre']);
        Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'id_segnalazione_correlata' => $precedente->id_segnalazione, 'testo_segnalazione' => 'Guasto tornato']);
        Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'testo_segnalazione' => 'Guasto nuovo']);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['filtro' => 'ricorrenti']))
            ->assertOk()
            ->assertSee('Guasto tornato')
            ->assertDontSee('Guasto nuovo');
    }

    public function test_cambio_vista_conserva_filtri(): void
    {
        $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['id_tipologia' => 3, 'livello_priorita' => 4]))
            ->assertOk()
            ->assertSee(route('gestione.dashboard', ['tab' => 'chiuse', 'id_tipologia' => 3, 'livello_priorita' => 4]));
    }

    public function test_colonna_scadenza_e_niente_toggle_riservata(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'data_scadenza_sla' => now()->subDays(2)]);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard'))
            ->assertOk()
            ->assertSee('>Scadenza<', false)
            ->assertSee('In ritardo di 2 g')
            ->assertDontSee(route('segnalazioni.toggle-riservata', $s->id_segnalazione), false);
    }

    public function test_da_assegnare_sotto_lo_stato(): void
    {
        Segnalazione::factory()->create(['id_stato_segnalazione' => 1]);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard'))
            ->assertOk()
            ->assertSee('>Stato<', false)
            ->assertDontSee('>Assegnata a<', false)
            ->assertSee('da assegnare')
            ->assertSee('pa-tono-arancio', false);
    }

    /** 1.0.4: sede e tipologia in colonna, tipologia e stato nei loro colori, niente "Media" né lucchetto. */
    public function test_riga_con_sede_tipologia_e_colori(): void
    {
        $ente = \App\Models\Istituto::create(['descrizione' => 'IC Test', 'id_categoria' => \App\Models\CategoriaEnte::factory()->create()->id_categoria, 'attivo' => true]);
        $plesso = \App\Models\Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => 'VIA VERROTTI SUD', 'indirizzo' => 'Via Verrotti']);
        $tipologia = \App\Models\TipologiaSegnalazione::where('descrizione', 'IMPIANTO IDRICO')->firstOrFail();
        $operaio = User::factory()->create(['name' => 'Vincenzo Caruso']);
        Segnalazione::factory()->create([
            'id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $operaio->id, 'id_plesso' => $plesso->id_plesso,
            'id_tipologia_segnalazione' => $tipologia->id_tipologia_segnalazione, 'livello_priorita' => 2, 'flag_riservata' => true,
            'titolo' => 'Cassetta del bagno da sistemare',
        ]);
        Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'livello_priorita' => 3, 'titolo' => 'Bagni bloccati']);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['>Sede<', '>Tipologia<', '>Stato<', '>Priorità<', '>Scadenza<', '>Segnalazione<'], false)
            ->assertSee('Via Verrotti Sud')
            ->assertSee('pa-tono-blu', false)
            ->assertSee('Impianto Idrico')
            ->assertSee('Vincenzo Caruso')
            ->assertDontSee('a Vincenzo Caruso')
            ->assertSee('Alta')
            ->assertDontSee('pa-priorita-2', false)
            ->assertDontSee('fa-lock', false);
    }

    /** 1.0.4: paginazione sopra e sotto l'elenco, legenda dei colori accanto. */
    public function test_paginazione_sopra_e_sotto_e_legenda(): void
    {
        Segnalazione::factory()->count(31)->create(['id_stato_segnalazione' => 1]);

        $html = $this->actingAs($this->gestore)->get(route('gestione.dashboard', ['gruppo' => '']))
            ->assertOk()
            ->assertSee('Legenda dei colori')
            ->assertSee('pa-legenda', false)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'pa-paginazione'), 'paginazione sopra e sotto');
        $this->assertStringContainsString('Verde e Giardini', $html);
    }
}
