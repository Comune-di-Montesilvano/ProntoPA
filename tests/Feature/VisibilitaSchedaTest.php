<?php

namespace Tests\Feature;

use App\Models\AdesioneSegnalazione;
use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Profilo;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** Chi apre la scheda = chi la vede in elenco (una regola sola), più i permessi nuovi del redesign. */
class VisibilitaSchedaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
    }

    private function utente(string $ruolo, array $attr = []): User
    {
        $u = User::factory()->create($attr);
        $u->assignRole($ruolo);

        return $u;
    }

    public function test_operaio_apre_scheda_assegnata(): void
    {
        $operaio = $this->utente('operaio');
        $s = Segnalazione::factory()->create(['id_operatore_assegnato' => $operaio->id, 'id_stato_segnalazione' => 3]);

        $this->actingAs($operaio)->get(route('segnalazioni.show', $s))->assertOk();
    }

    public function test_operaio_non_apre_altrui(): void
    {
        $operaio = $this->utente('operaio');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3]);

        $this->actingAs($operaio)->get(route('segnalazioni.show', $s))->assertForbidden();
    }

    public function test_caposquadra_apre_lavori_squadra(): void
    {
        $capo = $this->utente('operaio');
        $squadra = Squadra::create(['nome' => 'Elettricisti', 'id_caposquadra' => $capo->id, 'attiva' => true]);
        $s = Segnalazione::factory()->create(['id_squadra_assegnata' => $squadra->id_squadra, 'id_stato_segnalazione' => 3]);

        $this->actingAs($capo)->get(route('segnalazioni.show', $s))->assertOk();
    }

    public function test_partecipante_apre_segnalazione_a_cui_ha_aderito(): void
    {
        $partecipante = $this->utente('segnalatore');
        $aderita = Segnalazione::factory()->create();
        $altra = Segnalazione::factory()->create(['id_plesso' => $aderita->id_plesso]);
        AdesioneSegnalazione::create(['id_segnalazione' => $aderita->id_segnalazione, 'id_utente' => $partecipante->id]);

        $this->actingAs($partecipante)->get(route('segnalazioni.show', $aderita))->assertOk();
        $this->actingAs($partecipante)->get(route('segnalazioni.show', $altra))->assertForbidden();
    }

    public function test_segnalatore_scuola_apre_cio_che_vede_in_elenco(): void
    {
        $istituto = Istituto::create(['descrizione' => 'IC Test']);
        $plesso = Plesso::create(['nome' => 'Plesso A', 'id_istituto' => $istituto->id_istituto]);
        $profilo = Profilo::create(['descrizione' => 'Scuola', 'limita_istituto' => true, 'id_istituto' => $istituto->id_istituto]);
        $segnalatore = $this->utente('segnalatore', ['id_profilo' => $profilo->id_profilo]);
        $delCollega = Segnalazione::factory()->create(['id_plesso' => $plesso->id_plesso]);

        $this->assertTrue(Segnalazione::visibileA($segnalatore)->whereKey($delCollega->getKey())->exists());
        $this->actingAs($segnalatore)->get(route('segnalazioni.show', $delCollega))->assertOk();
    }

    public function test_comunica_fine_lavori_ditta_e_operaio_assegnati(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Ditta']);
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $ditta = $this->utente('impresa', ['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $operaio = $this->utente('operaio');
        $gestore = $this->utente('gestore', ['supervisore_segnalazioni' => true]);

        $allaDitta = Segnalazione::factory()->create(['id_appalto' => $appalto->id_appalto, 'id_stato_segnalazione' => 4]);
        $chiusa = Segnalazione::factory()->create(['id_appalto' => $appalto->id_appalto, 'id_stato_segnalazione' => 7, 'data_chiusura' => now()]);
        $allOperaio = Segnalazione::factory()->create(['id_operatore_assegnato' => $operaio->id, 'id_stato_segnalazione' => 3]);

        $this->assertTrue(Gate::forUser($ditta)->allows('comunicaFineLavori', $allaDitta));
        $this->assertFalse(Gate::forUser($ditta)->allows('comunicaFineLavori', $chiusa));
        $this->assertTrue(Gate::forUser($operaio)->allows('comunicaFineLavori', $allOperaio));
        $this->assertFalse(Gate::forUser($gestore)->allows('comunicaFineLavori', $allOperaio));
    }

    public function test_sollecita_e_accorpa_solo_comune(): void
    {
        $gestore = $this->utente('gestore', ['supervisore_segnalazioni' => true]);
        $segnalatore = $this->utente('segnalatore');
        $s = Segnalazione::factory()->create(['id_utente_segnalazione' => $segnalatore->id, 'id_stato_segnalazione' => 3]);

        $this->assertTrue(Gate::forUser($gestore)->allows('sollecita', $s));
        $this->assertTrue(Gate::forUser($gestore)->allows('accorpa', $s));
        $this->assertFalse(Gate::forUser($segnalatore)->allows('sollecita', $s));
        $this->assertFalse(Gate::forUser($segnalatore)->allows('accorpa', $s));
    }
}
