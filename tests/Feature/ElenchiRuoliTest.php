<?php

namespace Tests\Feature;

use App\Models\AdesioneSegnalazione;
use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Elenchi di segnalatore, ditta e operaio: stessi componenti, colonne per ruolo. */
class ElenchiRuoliTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
    }

    public function test_ditta_vede_segnalazione_su_appalto_scaduto(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica']);
        $scaduto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'Vecchio appalto', 'id_impresa' => $impresa->id_impresa, 'valido' => false]);
        $ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $ditta->assignRole('impresa');
        Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $scaduto->id_appalto, 'testo_segnalazione' => 'Lavoro su appalto scaduto']);

        $this->actingAs($ditta)->get(route('imprese.dashboard'))
            ->assertOk()
            ->assertSee('Lavoro su appalto scaduto')
            ->assertSee('>Entro<', false);
    }

    public function test_ditta_vista_preventivi_mostra_stato_5(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica']);
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'Appalto', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $ditta->assignRole('impresa');
        Segnalazione::factory()->create(['id_stato_segnalazione' => 5, 'id_appalto' => $appalto->id_appalto, 'testo_segnalazione' => 'Preventivo inviato']);
        Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto, 'testo_segnalazione' => 'Lavoro da fare']);

        $this->actingAs($ditta)->get(route('imprese.dashboard', ['tab' => 'preventivo']))
            ->assertOk()
            ->assertSee('Preventivo inviato')
            ->assertDontSee('Lavoro da fare');
    }

    public function test_segnalatore_vede_segui_per_adesione_e_niente_priorita(): void
    {
        $segnalatore = User::factory()->create();
        $segnalatore->assignRole('segnalatore');
        Segnalazione::factory()->create(['id_utente_segnalazione' => $segnalatore->id, 'testo_segnalazione' => 'La mia segnalazione']);
        $altrui = Segnalazione::factory()->create(['testo_segnalazione' => 'Segnalazione di un altro']);
        AdesioneSegnalazione::create(['id_segnalazione' => $altrui->id_segnalazione, 'id_utente' => $segnalatore->id]);

        foreach (['segnalatore.dashboard', 'segnalazioni.index'] as $rotta) {
            $this->actingAs($segnalatore)->get(route($rotta))
                ->assertOk()
                ->assertSeeInOrder(['Segnalazione di un altro', 'Segui'])
                ->assertSee('La mia segnalazione')
                ->assertDontSee('>Priorità<', false)
                ->assertDontSee('>Assegnata a<', false);
        }
    }

    public function test_operaio_vede_lavoro_assegnato_e_scheda_risponde(): void
    {
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $operaio->id, 'testo_segnalazione' => 'Cambiare la serratura']);

        $this->actingAs($operaio)->get(route('operaio.dashboard'))
            ->assertOk()
            ->assertSee('Cambiare la serratura')
            ->assertSee(route('segnalazioni.show', $s->id_segnalazione), false)
            ->assertSee('>Entro<', false);

        $this->actingAs($operaio)->get(route('segnalazioni.show', $s->id_segnalazione))->assertOk();
    }
}
