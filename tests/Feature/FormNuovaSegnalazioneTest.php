<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\Segnalazione;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

/** 1.0.0: il form nuova segnalazione rifatto da zero, uguale per tutti. */
class FormNuovaSegnalazioneTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    private User $urp;

    private Istituto $ente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->ente = $this->istitutoConPlessi();
        $this->urp = User::factory()->create(['auth_source' => 'ldap']);
        $this->urp->assignRole('segnalatore');
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function dati(array $extra = []): array
    {
        return array_merge([
            'id_plesso' => $this->idPlessi($this->ente)[0],
            'id_tipologia_segnalazione' => 1,
            'titolo' => 'Lampada fulminata',
            'testo_segnalazione' => 'In aula 3 la lampada non si accende.',
        ], $extra);
    }

    public function test_form_campi_in_ordine_senza_dettagli_ne_mappa(): void
    {
        $this->actingAs($this->urp)->get(route('segnalazioni.create'))
            ->assertOk()
            ->assertSeeInOrder(['Sede', 'Tipologia', 'Priorità', 'Titolo', 'Descrizione', 'Dove si trova il problema', 'Foto', 'Invia segnalazione'])
            ->assertDontSee('Più dettagli')
            ->assertDontSee('Per conto di')
            ->assertDontSee('Provenienza')
            ->assertDontSee('Fornitura 15 panchine')
            ->assertDontSee('Salva e inserisci nuova')
            ->assertDontSee('id="mappa', false)
            ->assertDontSee('name="latitudine"', false);
    }

    public function test_prima_istituto_poi_sede_senza_categoria_ne_codice(): void
    {
        $altro = $this->istitutoConPlessi('PEIC777777', 'peic777777@istruzione.it');
        $categoria = $this->ente->categoria->nome;

        $html = $this->actingAs($this->urp)->get(route('segnalazioni.create'))
            ->assertOk()
            ->assertSeeInOrder(['Istituto', 'Sede'])
            ->assertSee("— Scegli l'istituto —", false)
            ->assertDontSee('<optgroup', false)
            ->assertDontSee($categoria)
            ->getContent();

        // Una select di sedi per istituto: solo quella dell'istituto scelto viene inviata.
        $this->assertStringContainsString('data-istituto="'.$this->ente->id_istituto.'"', $html);
        $this->assertStringContainsString('data-istituto="'.$altro->id_istituto.'"', $html);
        $this->assertSame(2, substr_count($html, 'name="id_plesso"'));
    }

    public function test_sede_unica_preselezionata(): void
    {
        $scuola = $this->utenteSpid();
        $this->delega($scuola, $this->ente, $this->idPlessi($this->ente)[1]);

        $this->actingAs($scuola)->get(route('segnalazioni.create'))
            ->assertOk()
            // Un solo istituto e una sola sede: niente da scegliere, solo i nomi.
            ->assertSee('type="hidden" name="id_plesso" value="'.$this->idPlessi($this->ente)[1].'"', false)
            ->assertSee('Plesso 2 PEIC828004')
            ->assertDontSee('<select name="id_plesso"', false)
            ->assertDontSee('id="istituto"', false)
            ->assertDontSee('Plesso 1 PEIC828004');
    }

    public function test_scuola_vede_solo_sedi_di_enti_attivi(): void
    {
        $scuola = $this->utenteSpid();
        $spento = $this->istitutoConPlessi('PEIC777777', 'peic777777@istruzione.it');
        $this->delega($scuola, $this->ente, null);
        $this->delega($scuola, $spento, null);
        $spento->update(['attivo' => false]);

        $this->actingAs($scuola)->get(route('segnalazioni.create'))
            ->assertOk()->assertSee('Plesso 1 PEIC828004')->assertDontSee('PEIC777777');

        $this->actingAs($scuola)->post(route('segnalazioni.store'), $this->dati(['id_plesso' => $this->idPlessi($spento)[0]]))
            ->assertSessionHasErrors('id_plesso');

        // Le segnalazioni già aperte su quell'ente restano visibili alla scuola.
        $vecchia = Segnalazione::factory()->create(['id_plesso' => $this->idPlessi($spento)[0]]);
        $this->actingAs($scuola)->get(route('segnalazioni.show', $vecchia))->assertOk();
    }

    public function test_personale_non_vede_sedi_di_enti_disattivati(): void
    {
        $spento = $this->istitutoConPlessi('PEIC777777', 'peic777777@istruzione.it');
        $spento->update(['attivo' => false]);

        $this->actingAs($this->urp)->get(route('segnalazioni.create'))
            ->assertOk()->assertSee('Plesso 1 PEIC828004')->assertDontSee('PEIC777777');
    }

    public function test_sede_obbligatoria(): void
    {
        $this->actingAs($this->urp)->post(route('segnalazioni.store'), $this->dati(['id_plesso' => null]))
            ->assertSessionHasErrors('id_plesso');
        $this->assertSame(0, Segnalazione::count());
    }

    public function test_dove_facoltativo_anche_per_scuola(): void
    {
        $scuola = $this->utenteSpid();
        $this->delega($scuola, $this->ente, null);

        $this->actingAs($scuola)->post(route('segnalazioni.store'), $this->dati())
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(0, (int) Segnalazione::firstOrFail()->ubicazione_tipo);
    }

    public function test_foto_facoltativa_e_salvata(): void
    {
        Storage::fake('local');
        $this->actingAs($this->urp)->post(route('segnalazioni.store'), $this->dati())->assertSessionHasNoErrors();

        $this->actingAs($this->urp)->post(route('segnalazioni.store'), $this->dati([
            'allegati' => [UploadedFile::fake()->image('guasto.jpg')],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, Segnalazione::count());
        $this->assertSame(1, Segnalazione::latest('id_segnalazione')->first()->allegati()->count());
    }

    public function test_campi_tolti_ignorati(): void
    {
        $this->actingAs($this->urp)->post(route('segnalazioni.store'), $this->dati([
            'id_provenienza' => 3, 'segnalante' => 'Mario', 'latitudine' => 42.5, 'id_specializzazione' => 2,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Segnalazione::count());
    }

    public function test_simili_per_sede_e_tipologia(): void
    {
        Cache::flush();
        Impostazione::set('adesioni_enabled', true);
        [$p1, $p2] = $this->idPlessi($this->ente);
        $stessa = Segnalazione::factory()->create(['id_plesso' => $p1, 'id_tipologia_segnalazione' => 1]);
        Segnalazione::factory()->create(['id_plesso' => $p1, 'id_tipologia_segnalazione' => 2]);
        Segnalazione::factory()->create(['id_plesso' => $p2, 'id_tipologia_segnalazione' => 1]);

        $this->actingAs($this->urp)->getJson(route('segnalazioni.simili', ['id_plesso' => $p1, 'id_tipologia_segnalazione' => 1]))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $stessa->id_segnalazione);
    }

    public function test_tipologie_disattive_non_nel_form(): void
    {
        TipologiaSegnalazione::create(['descrizione' => 'VECCHIA SPENTA', 'icona' => 'fas fa-star', 'attiva' => false]);

        $this->actingAs($this->urp)->get(route('segnalazioni.create'))
            ->assertOk()->assertSee('IMPIANTO ELETTRICO')->assertDontSee('VECCHIA SPENTA');

        $id = TipologiaSegnalazione::where('descrizione', 'VECCHIA SPENTA')->value('id_tipologia_segnalazione');
        $this->actingAs($this->urp)->post(route('segnalazioni.store'), $this->dati(['id_tipologia_segnalazione' => $id]))
            ->assertSessionHasErrors('id_tipologia_segnalazione');
    }
}
