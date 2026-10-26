<?php

namespace Tests\Feature;

use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/** Collega a precedente: il guasto si ripresenta dopo una riparazione già chiusa. La nuova resta aperta. */
class CollegaTest extends TestCase
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

    private function chiusa(array $attr = []): Segnalazione
    {
        return Segnalazione::factory()->create(array_merge([
            'id_plesso' => 5, 'id_tipologia_segnalazione' => 1,
            'id_stato_segnalazione' => 7, 'data_chiusura' => now()->subMonths(2),
        ], $attr));
    }

    /** @return list<int> */
    private function candidati(Segnalazione $s): array
    {
        return collect($this->actingAs($this->admin)->getJson(route('segnalazioni.candidati-collega', $s))
            ->assertOk()->json())->pluck('id')->all();
    }

    public function test_candidati_solo_chiuse_stessa_sede_e_tipologia_ultimi_24_mesi(): void
    {
        $nuova = Segnalazione::factory()->create(['id_plesso' => 5, 'id_tipologia_segnalazione' => 1]);
        $buona = $this->chiusa();
        $aperta = Segnalazione::factory()->create(['id_plesso' => 5, 'id_tipologia_segnalazione' => 1]);
        $altraSede = $this->chiusa(['id_plesso' => 6]);
        $vecchia = $this->chiusa(['data_chiusura' => now()->subMonths(25)]);

        $ids = $this->candidati($nuova);

        $this->assertContains($buona->id_segnalazione, $ids);
        $this->assertNotContains($aperta->id_segnalazione, $ids);
        $this->assertNotContains($altraSede->id_segnalazione, $ids);
        $this->assertNotContains($vecchia->id_segnalazione, $ids);
        $this->assertNotContains($nuova->id_segnalazione, $ids);
    }

    public function test_candidati_cerca_chiuse_per_numero(): void
    {
        $nuova = Segnalazione::factory()->create();
        $cercata = $this->chiusa(['id_plesso' => 99]);

        $risposta = $this->actingAs($this->admin)
            ->getJson(route('segnalazioni.candidati-collega', $nuova).'?q='.$cercata->id_segnalazione)
            ->assertOk()->json();

        $this->assertSame($cercata->id_segnalazione, $risposta[0]['id']);
    }

    public function test_riparazione_precedente_con_ditta(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica']);
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $prima = $this->chiusa(['id_appalto' => $appalto->id_appalto, 'data_chiusura' => now()->subDays(40)]);
        $nuova = Segnalazione::factory()->create(['id_segnalazione_correlata' => $prima->id_segnalazione]);

        $info = $nuova->riparazionePrecedente();

        $this->assertTrue($nuova->isRicorrente());
        $this->assertSame($prima->id_segnalazione, $info['id']);
        $this->assertSame('Edil Adriatica', $info['da']);
        $this->assertSame(40, $info['giorni']);
    }

    public function test_senza_collegamento_non_ricorrente(): void
    {
        $s = Segnalazione::factory()->create();

        $this->assertFalse($s->isRicorrente());
        $this->assertNull($s->riparazionePrecedente());
    }

    public function test_collega_via_azione_lascia_aperta(): void
    {
        $prima = $this->chiusa();
        $nuova = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);

        $this->actingAs($this->admin)->post(route('segnalazioni.azione', $nuova), [
            'id_azione' => 11, 'id_segnalazione_correlata' => $prima->id_segnalazione,
        ])->assertSessionHasNoErrors();

        $nuova->refresh();
        $this->assertSame($prima->id_segnalazione, (int) $nuova->id_segnalazione_correlata);
        $this->assertFalse($nuova->isChiusa());
        $this->assertSame(2, $nuova->statoEnum()->value);
    }
}
