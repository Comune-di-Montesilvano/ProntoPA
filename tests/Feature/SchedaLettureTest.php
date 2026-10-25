<?php

namespace Tests\Feature;

use App\Models\Appalto;
use App\Models\Impresa;
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

/** Una scheda, tre letture: gestore (pratica), lavoro (ditta/operaio), segnalante (tracciamento). */
class SchedaLettureTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $operaio;

    private User $segnalatore;

    private User $ditta;

    private Segnalazione $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);

        $this->gestore = User::factory()->create(['name' => 'Anna Gestore', 'supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $this->operaio = User::factory()->create(['name' => 'Paolo Neri']);
        $this->operaio->assignRole('operaio');
        $this->segnalatore = User::factory()->create(['name' => 'Giulia Ferri']);
        $this->segnalatore->assignRole('segnalatore');

        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica']);
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'Manutenzione edile', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $this->ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $this->ditta->assignRole('impresa');

        $this->s = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $this->segnalatore->id,
            'id_stato_segnalazione' => 4,
            'id_appalto' => $appalto->id_appalto,
            'id_operatore_assegnato' => $this->operaio->id,
            'testo_segnalazione' => 'Finestra rotta in aula 3',
            'latitudine' => 42.46, 'longitudine' => 14.2,
        ]);
    }

    public function test_gestore_vede_prossimo_passo_e_menu(): void
    {
        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertSee('Edil Adriatica deve chiudere il lavoro')
            ->assertSee(route('segnalazioni.stampa', $this->s->id_segnalazione), false)
            ->assertSee(route('segnalazioni.fascicolo-pdf', $this->s), false)
            ->assertSee(route('segnalazioni.priorita', $this->s->id_segnalazione), false)
            ->assertSee(route('segnalazioni.sollecita', $this->s), false)
            ->assertSee('Finestra rotta in aula 3');
    }

    public function test_ditta_non_vede_evidenza_riservata_priorita(): void
    {
        $this->actingAs($this->ditta)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertDontSee(route('segnalazioni.evidenza', $this->s->id_segnalazione), false)
            ->assertDontSee(route('segnalazioni.toggle-riservata', $this->s->id_segnalazione), false)
            ->assertDontSee(route('segnalazioni.priorita', $this->s->id_segnalazione), false);
    }

    public function test_ditta_vede_fine_lavori_e_naviga(): void
    {
        $this->actingAs($this->ditta)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertSee('Comunica fine lavori')
            ->assertSee(route('segnalazioni.fine-lavori', $this->s), false)
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=42.46,14.2', false)
            ->assertSee('Messaggi con il comune');
    }

    public function test_ditta_vede_chiama_referente(): void
    {
        $istituto = Istituto::create(['descrizione' => 'IC Rodari', 'tipo' => 'Scuola', 'tipo_ente' => 'scuola', 'attivo' => true]);
        $plesso = Plesso::create(['id_istituto' => $istituto->id_istituto, 'nome' => 'Plesso Rodari', 'referente' => 'Maria Bianchi', 'recapiti' => '085 123456']);
        $this->s->update(['id_plesso' => $plesso->id_plesso]);

        $this->actingAs($this->ditta)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertSee('tel:085123456', false);
    }

    public function test_segnalante_non_vede_nomi_interni(): void
    {
        NotaSegnalazione::create([
            'id_segnalazione' => $this->s->id_segnalazione, 'testo' => 'Passeremo giovedì',
            'id_utente' => $this->gestore->id, 'visibile_web' => true, 'visibile_impresa' => false,
        ]);

        $this->actingAs($this->segnalatore)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertSee('Passeremo giovedì')
            ->assertSee('Ufficio manutenzioni')
            ->assertDontSee('Anna Gestore')
            ->assertDontSee('Paolo Neri')
            ->assertDontSee('Edil Adriatica')
            ->assertDontSee('Manutenzione edile');
    }

    public function test_segnalante_vede_composer(): void
    {
        $this->actingAs($this->segnalatore)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertSee(route('segnalazioni.nota', $this->s->id_segnalazione), false)
            ->assertSee('Invia al comune')
            ->assertSee('Ci stiamo lavorando');
    }

    public function test_segnalante_accorpata_vede_link_madre_senza_composer(): void
    {
        $madre = Segnalazione::factory()->create(['id_utente_segnalazione' => $this->segnalatore->id]);
        $this->s->update(['id_stato_segnalazione' => 8, 'data_chiusura' => now(), 'id_segnalazione_madre' => $madre->id_segnalazione]);

        $this->actingAs($this->segnalatore)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertSee(route('segnalazioni.show', $madre->id_segnalazione), false)
            ->assertDontSee('Invia al comune');
    }

    public function test_operaio_vede_lettura_lavoro(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $this->operaio->id]);

        $this->actingAs($this->operaio)->get(route('segnalazioni.show', $s))
            ->assertOk()
            ->assertSee('Comunica fine lavori')
            ->assertSee('Messaggi con il comune')
            ->assertDontSee(route('segnalazioni.priorita', $s->id_segnalazione), false);
    }

    public function test_scheda_non_contiene_vecchie_linguette(): void
    {
        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertDontSee('Gestione Segnalazione')
            ->assertDontSee('Unisci duplicato')
            ->assertDontSee('Storico segnalazione');
    }
}
