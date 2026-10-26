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
            ->assertSee('Superata da 2 g')
            ->assertDontSee(route('segnalazioni.toggle-riservata', $s->id_segnalazione), false);
    }

    public function test_da_assegnare_in_colonna_assegnata_a(): void
    {
        Segnalazione::factory()->create(['id_stato_segnalazione' => 1]);

        $this->actingAs($this->gestore)->get(route('gestione.dashboard'))
            ->assertOk()
            ->assertSee('>Assegnata a<', false)
            ->assertSee('Da assegnare');
    }
}
