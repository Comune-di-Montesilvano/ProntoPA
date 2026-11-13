<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Support\Impostazioni\Sezioni;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** 1.0.0: niente gruppi di tipologie e niente coordinate per segnalazione (conta la sede). */
class TogliGruppiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_schema_senza_gruppi_ne_coordinate(): void
    {
        $this->assertFalse(Schema::hasTable('gruppi_segnalazioni'));
        $this->assertFalse(Schema::hasColumn('tipologie_segnalazioni', 'id_gruppo'));
        $this->assertFalse(Schema::hasColumn('appalti', 'id_gruppo'));
        foreach (['latitudine', 'longitudine', 'zoom'] as $colonna) {
            $this->assertFalse(Schema::hasColumn('segnalazioni', $colonna), $colonna);
        }
        $this->assertTrue(Schema::hasColumn('plessi', 'latitudine'), 'la posizione resta sulla sede');
    }

    public function test_scheda_e_stampe_mostrano_la_tipologia_senza_gruppo(): void
    {
        Impostazione::set('ente_nome', 'Comune di Prova');
        $s = Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1]);

        $this->actingAs($this->admin)->get(route('segnalazioni.show', $s))
            ->assertOk()->assertSee('IMPIANTO ELETTRICO')->assertDontSee('EDILIZIA SCOLASTICA');
        $this->actingAs($this->admin)->get(route('segnalazioni.stampa', $s))
            ->assertOk()->assertSee('Comune di Prova')->assertDontSee('EDILIZIA SCOLASTICA');
        $this->actingAs($this->admin)->get(route('gestione.stampa-schede', ['ids' => [$s->id_segnalazione]]))
            ->assertOk()->assertSee('IMPIANTO ELETTRICO');
        $this->actingAs($this->admin)->get(route('segnalazioni.fascicolo-pdf', $s))->assertOk();
    }

    public function test_tipologie_gestori_elenco_piatto(): void
    {
        $gestore = User::factory()->create(['email' => 'g@example.com']);
        $gestore->assignRole('gestore');

        $this->actingAs($this->admin)->get(route('organizzazione.tipologie-gestori.index'))
            ->assertOk()->assertSee('IMPIANTO ELETTRICO')->assertDontSee('Senza gruppo');
        $this->actingAs($this->admin)->get(route('organizzazione.tipologie-gestori.edit', $gestore))
            ->assertOk()->assertSee('IMPIANTO ELETTRICO')->assertDontSee('Tutto il gruppo');
    }

    public function test_dedup_solo_per_stessa_sede(): void
    {
        Cache::flush();
        Impostazione::set('adesioni_enabled', true);
        $aperta = Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1, 'id_plesso' => 5]);
        Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1, 'id_plesso' => 6]);

        $this->actingAs($this->admin)->getJson(route('segnalazioni.simili', ['id_tipologia_segnalazione' => 1, 'id_plesso' => 5]))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $aperta->id_segnalazione);

        // Coordinate inviate da client vecchi: ignorate, senza sede nessun simile.
        $this->actingAs($this->admin)->getJson(route('segnalazioni.simili', ['id_tipologia_segnalazione' => 1, 'latitudine' => 42.5, 'longitudine' => 14.1]))
            ->assertOk()->assertJsonCount(0);
    }

    public function test_impostazione_dedup_raggio_rimossa(): void
    {
        $this->assertNull(Impostazione::find('dedup_raggio_metri'));
        $this->assertArrayNotHasKey('dedup_raggio_metri', Sezioni::SEGNALAZIONI);
        $this->actingAs($this->admin)->get(route('admin.regole.segnalazioni'))
            ->assertOk()->assertDontSee('dedup_raggio_metri');
    }
}
