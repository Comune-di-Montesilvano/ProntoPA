<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\AllegatoSegnalazione;
use App\Models\Appalto;
use App\Models\Impostazione;
use App\Models\Impresa;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\AccorpataNotification;
use App\Services\SegnalazioneWorkflowService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Accorpa: la nuova segnalazione confluisce nella vecchia, che ne esce sollecitata. */
class AccorpaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Cache::flush();
        Notification::fake();

        $this->admin = User::factory()->create(['name' => 'Anna Admin', 'supervisore_segnalazioni' => true]);
        $this->admin->assignRole('admin');
    }

    private function accorpa(Segnalazione $nuova, Segnalazione $destinazione, ?User $chi = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($chi ?? $this->admin)->post(route('segnalazioni.accorpa', $nuova), [
            'id_destinazione' => $destinazione->id_segnalazione,
        ]);
    }

    public function test_accorpa_chiude_nuova_imposta_madre_e_crea_adesione(): void
    {
        $vecchia = Segnalazione::factory()->create();
        $nuova = Segnalazione::factory()->create(['segnalante' => 'Pina Verdi', 'email' => 'pina@example.com']);

        $this->accorpa($nuova, $vecchia)->assertRedirect(route('segnalazioni.show', $vecchia->id_segnalazione));

        $nuova->refresh();
        $this->assertTrue($nuova->isChiusa());
        $this->assertSame(SegnalazioneStato::DUPLICATA, $nuova->statoEnum());
        $this->assertSame($vecchia->id_segnalazione, (int) $nuova->id_segnalazione_madre);
        $this->assertSame('Pina Verdi', $vecchia->adesioni()->first()?->segnalante);
    }

    public function test_allegati_e_adesioni_spostati(): void
    {
        $vecchia = Segnalazione::factory()->create();
        $nuova = Segnalazione::factory()->create();
        AllegatoSegnalazione::create(['id_segnalazione' => $nuova->id_segnalazione, 'percorso' => 'x.jpg', 'tipo' => 'image/jpeg', 'nome_originale' => 'x.jpg', 'dimensione' => 1, 'id_utente_creazione' => $this->admin->id]);
        $aderente = User::factory()->create();
        $nuova->adesioni()->create(['id_utente' => $aderente->id]);

        $this->accorpa($nuova, $vecchia);

        $this->assertSame(1, $vecchia->allegati()->count());
        $this->assertSame(2, $vecchia->adesioni()->count());
    }

    public function test_negato_su_destinazione_non_gestibile(): void
    {
        $gestore = User::factory()->create(['supervisore_segnalazioni' => false]);
        $gestore->assignRole('gestore');
        $nuova = Segnalazione::factory()->create(['id_operatore_assegnato' => $gestore->id]);
        $vecchia = Segnalazione::factory()->create();

        $this->accorpa($nuova, $vecchia, $gestore)->assertForbidden();
        $this->assertFalse($nuova->fresh()->isChiusa());
    }

    public function test_negato_a_segnalatore(): void
    {
        $segnalatore = User::factory()->create();
        $segnalatore->assignRole('segnalatore');
        $nuova = Segnalazione::factory()->create(['id_utente_segnalazione' => $segnalatore->id]);

        $this->accorpa($nuova, Segnalazione::factory()->create(), $segnalatore)->assertForbidden();
    }

    public function test_su_se_stessa_rifiutato(): void
    {
        $s = Segnalazione::factory()->create();

        $this->accorpa($s, $s)->assertSessionHasErrors('id_destinazione');
    }

    public function test_su_chiusa_rifiutato(): void
    {
        $vecchia = Segnalazione::factory()->create(['id_stato_segnalazione' => 7, 'data_chiusura' => now()]);

        $this->accorpa(Segnalazione::factory()->create(), $vecchia)->assertSessionHasErrors('id_destinazione');
    }

    public function test_nota_con_testo_sulla_vecchia(): void
    {
        $vecchia = Segnalazione::factory()->create();
        $nuova = Segnalazione::factory()->create(['segnalante' => 'Pina Verdi', 'testo_segnalazione' => 'Perde ancora acqua']);

        $this->accorpa($nuova, $vecchia);

        $nota = NotaSegnalazione::where('id_segnalazione', $vecchia->id_segnalazione)->firstOrFail();
        $this->assertStringContainsString('Segnalata di nuovo da Pina Verdi', $nota->testo);
        $this->assertStringContainsString('Perde ancora acqua', $nota->testo);
        $this->assertFalse($nota->visibile_web);
        $this->assertFalse($nota->visibile_impresa);
    }

    public function test_nota_pubblica_sulla_nuova(): void
    {
        $vecchia = Segnalazione::factory()->create();
        $nuova = Segnalazione::factory()->create();

        $this->accorpa($nuova, $vecchia);

        $nota = NotaSegnalazione::where('id_segnalazione', $nuova->id_segnalazione)->firstOrFail();
        $this->assertSame("Era già stata segnalata: segui gli aggiornamenti nella #{$vecchia->id_segnalazione}.", $nota->testo);
        $this->assertTrue($nota->visibile_web);
    }

    public function test_catena_risale_alla_madre(): void
    {
        $madre = Segnalazione::factory()->create();
        $accorpata = Segnalazione::factory()->create([
            'id_stato_segnalazione' => 8, 'data_chiusura' => now(), 'id_segnalazione_madre' => $madre->id_segnalazione,
        ]);
        $nuova = Segnalazione::factory()->create();

        $this->accorpa($nuova, $accorpata)->assertRedirect(route('segnalazioni.show', $madre->id_segnalazione));

        $this->assertSame($madre->id_segnalazione, (int) $nuova->fresh()->id_segnalazione_madre);
    }

    public function test_assegnatario_ditta_notificato(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil']);
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $delegato = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa]);
        $delegato->assignRole('impresa');
        $vecchia = Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);

        $this->accorpa(Segnalazione::factory()->create(), $vecchia);

        Notification::assertSentTo($delegato, AccorpataNotification::class);
        $nota = NotaSegnalazione::where('id_segnalazione', $vecchia->id_segnalazione)->firstOrFail();
        $this->assertTrue($nota->visibile_impresa);
    }

    public function test_senza_assegnatario_avvisa_supervisori(): void
    {
        $supervisore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $supervisore->assignRole('gestore');

        $this->accorpa(Segnalazione::factory()->create(), Segnalazione::factory()->create());

        Notification::assertSentTo($supervisore, AccorpataNotification::class);
    }

    public function test_segnalante_nuova_notificato_e_apre_vecchia(): void
    {
        $segnalatore = User::factory()->create();
        $segnalatore->assignRole('segnalatore');
        $vecchia = Segnalazione::factory()->create();
        $nuova = Segnalazione::factory()->create(['id_utente_segnalazione' => $segnalatore->id]);

        $this->accorpa($nuova, $vecchia);

        Notification::assertSentTo($segnalatore, AccorpataNotification::class);
        $this->actingAs($segnalatore)->get(route('segnalazioni.show', $vecchia))->assertOk();
    }

    public function test_segnalante_anonimo_avvisato_per_email(): void
    {
        $nuova = Segnalazione::factory()->create(['email' => 'pina@example.com']);

        $this->accorpa($nuova, Segnalazione::factory()->create());

        Notification::assertSentOnDemand(AccorpataNotification::class, fn ($n, $c, $d) => $d->routes['mail'] === 'pina@example.com');
    }

    public function test_segna_duplicata_non_piu_tra_azioni(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);

        $codici = app(SegnalazioneWorkflowService::class)->getAzioniDisponibili($s, $this->admin)->pluck('codice');

        $this->assertNotContains('segna_duplicata', $codici);
    }

    public function test_candidati_json_include_simile_ed_esclude_se_stessa(): void
    {
        $posto = ['latitudine' => 42.5, 'longitudine' => 14.1, 'id_tipologia_segnalazione' => 1];
        $simile = Segnalazione::factory()->create($posto);
        $altrove = Segnalazione::factory()->create(['latitudine' => 45.0, 'longitudine' => 9.0]);
        $nuova = Segnalazione::factory()->create($posto);

        $ids = collect($this->actingAs($this->admin)->getJson(route('segnalazioni.candidati-accorpa', $nuova))
            ->assertOk()->json())->pluck('id');

        $this->assertContains($simile->id_segnalazione, $ids);
        $this->assertNotContains($nuova->id_segnalazione, $ids);
        $this->assertNotContains($altrove->id_segnalazione, $ids);
    }

    public function test_candidati_automatici_solo_tra_quelle_visibili(): void
    {
        $gestore = User::factory()->create(['supervisore_segnalazioni' => false]);
        $gestore->assignRole('gestore');
        $posto = ['latitudine' => 42.5, 'longitudine' => 14.1, 'id_tipologia_segnalazione' => 1];
        $sua = Segnalazione::factory()->create($posto + ['id_operatore_assegnato' => $gestore->id]);
        $altrui = Segnalazione::factory()->create($posto);
        $nuova = Segnalazione::factory()->create($posto + ['id_operatore_assegnato' => $gestore->id]);

        $ids = collect($this->actingAs($gestore)->getJson(route('segnalazioni.candidati-accorpa', $nuova))
            ->assertOk()->json())->pluck('id');

        $this->assertContains($sua->id_segnalazione, $ids);
        $this->assertNotContains($altrui->id_segnalazione, $ids);
    }

    public function test_candidati_cerca_per_numero(): void
    {
        $cercata = Segnalazione::factory()->create();
        $nuova = Segnalazione::factory()->create();

        $risposta = $this->actingAs($this->admin)
            ->getJson(route('segnalazioni.candidati-accorpa', $nuova).'?q='.$cercata->id_segnalazione)
            ->assertOk()->json();

        $this->assertSame($cercata->id_segnalazione, $risposta[0]['id']);
    }

    public function test_volte_segnalata_e_sollecitata(): void
    {
        $vecchia = Segnalazione::factory()->create();
        $this->assertSame(1, $vecchia->volteSegnalata());
        $this->assertFalse($vecchia->isSollecitata());

        $this->accorpa(Segnalazione::factory()->create(), $vecchia);

        $vecchia->refresh();
        $this->assertSame(2, $vecchia->volteSegnalata());
        $this->assertTrue($vecchia->isSollecitata());
    }
}
