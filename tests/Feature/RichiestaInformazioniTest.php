<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Jobs\RicalcolaScadenzeSla;
use App\Models\AllegatoSegnalazione;
use App\Models\Azione;
use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\NotaSegnalazione;
use App\Models\RichiestaInformazioni;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\User;
use App\Notifications\RichiestaInformazioniNotification;
use App\Notifications\RispostaInformazioniNotification;
use App\Notifications\SegnalazioneChiusaNotification;
use App\Services\SegnalazioneWorkflowService;
use App\Services\Segnalazioni\RichiesteInformazioni;
use App\Services\Sla\CalendarioUfficio;
use App\Services\SlaService;
use App\Support\Segnalazioni\Scadenza;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Richiesta di informazioni a chi ha segnalato: stato 11 e scadenza ferma durante l'attesa. */
class RichiestaInformazioniTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $autore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00', 'Europe/Rome'));
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $this->autore = User::factory()->create(['auth_source' => 'ldap', 'name' => 'Giulia Ferri']);
        $this->autore->assignRole('segnalatore');
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
    }

    private function segnalazione(int $stato = 2, array $altro = []): Segnalazione
    {
        return Segnalazione::factory()->create($altro + [
            'id_stato_segnalazione' => $stato, 'id_utente_segnalazione' => $this->autore->id, 'data_segnalazione' => now(),
        ]);
    }

    private function chiedi(Segnalazione $s): RichiestaInformazioni
    {
        return app(RichiesteInformazioni::class)->chiedi($s, $this->gestore, 'Quale aula?', 10, true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Richiesta conclusa dopo $ore ore lavorative dall'apertura della segnalazione. */
    private function conRichiestaConclusa(float $ore): Segnalazione
    {
        SlaConfigurazione::updateOrCreate(['id_tipologia_segnalazione' => null, 'livello_priorita' => 2], ['ore_target' => 2, 'unita' => 'giorni', 'ore_warning' => 4]);
        $s = Segnalazione::factory()->create(['livello_priorita' => 2, 'id_stato_segnalazione' => 2, 'data_segnalazione' => now()]);
        $calendario = CalendarioUfficio::daImpostazioni();
        RichiestaInformazioni::create([
            'id_segnalazione' => $s->id_segnalazione, 'id_utente' => $this->gestore->id, 'testo' => 'Quale aula?',
            'stato_precedente' => 2, 'termine_at' => now()->addDays(10), 'chiudi_senza_risposta' => true,
            'conclusa_at' => $calendario->aggiungiOreLavorative(now(), $ore), 'esito' => 'risposta',
        ]);

        return $s->fresh();
    }

    public function test_seeder_crea_stato_11_e_azione_idempotente(): void
    {
        $this->seed(TabelleRiferimentoSeeder::class);

        $this->assertSame(1, DB::table('db_stato_segnalazioni')->where('id_stato', 11)->count());
        $azione = DB::table('db_azioni')->where('codice', 'chiedi_informazioni')->get();
        $this->assertCount(1, $azione);
        $this->assertSame([1, 2, 3, 4], json_decode($azione[0]->parametri_filtro, true)['stati']);
        $this->assertSame(11, (int) $azione[0]->id_stato_segnalazione);
    }

    public function test_scadenza_spostata_delle_ore_lavorative_attese(): void
    {
        $s = $this->conRichiestaConclusa(6);
        $calendario = CalendarioUfficio::daImpostazioni();

        $this->assertEqualsWithDelta(6.0, RichiestaInformazioni::oreDiAttesa($s, $calendario), 0.01);
        $attesa = $calendario->aggiungiOreLavorative($calendario->aggiungiGiorniLavorativi($s->data_segnalazione, 2), 6);
        $this->assertSame($attesa->format('Y-m-d H:i'), app(SlaService::class)->calcolaScadenza($s)->format('Y-m-d H:i'));
    }

    public function test_ricalcolo_sla_mantiene_lo_spostamento(): void
    {
        $s = $this->conRichiestaConclusa(6);
        $attesa = app(SlaService::class)->calcolaScadenza($s);

        RicalcolaScadenzeSla::dispatchSync();

        $this->assertSame($attesa->format('Y-m-d H:i'), $s->fresh()->data_scadenza_sla->format('Y-m-d H:i'));
    }

    public function test_sla_check_e_inattivita_ignorano_stato_11(): void
    {
        SlaConfigurazione::query()->update(['giorni_avviso_inattivita' => 1, 'giorni_archiviazione_inattivita' => 2]);
        $s = Segnalazione::factory()->create([
            'id_stato_segnalazione' => 11, 'data_segnalazione' => now()->subDays(30),
            'data_scadenza_sla' => now()->subDays(5), 'sla_violato' => false,
        ]);

        $this->artisan('sla:check')->assertSuccessful();
        $this->artisan('segnalazioni:inattivita')->assertSuccessful();

        $s->refresh();
        $this->assertFalse((bool) $s->sla_violato);
        $this->assertSame(SegnalazioneStato::ATTESA_RISPOSTA, $s->statoEnum());
        $this->assertNull($s->avviso_inattivita_at);
    }

    public function test_scadenza_in_parole_ferma(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 11, 'data_scadenza_sla' => now()->subDay()]);

        $ferma = ['testo' => 'Ferma: aspetta una risposta', 'tono' => 'slate'];
        $this->assertSame($ferma, Scadenza::per($s));
        $this->assertSame($ferma, Scadenza::estesa($s));
        $this->assertSame('In attesa di risposta', SegnalazioneStato::ATTESA_RISPOSTA->etichetta());
        $this->assertFalse(SegnalazioneStato::ATTESA_RISPOSTA->isTerminale());
    }

    public function test_nessuna_azione_menu_in_stato_11(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 11]);

        $this->assertCount(0, app(SegnalazioneWorkflowService::class)->getAzioniDisponibili($s, $admin));
    }

    public function test_chiedi_informazioni_mai_nel_menu_generico(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);

        $codici = app(SegnalazioneWorkflowService::class)->getAzioniDisponibili($s, $this->gestore)->pluck('codice');

        $this->assertNotContains('chiedi_informazioni', $codici->all());
    }

    public function test_chiedi_porta_a_11_con_assegnatario_invariato_e_avvisa_autore(): void
    {
        Notification::fake();
        $appalto = Appalto::create(['descrizione' => 'Manutenzione', 'id_impresa' => Impresa::create(['ragione_sociale' => 'Edil'])->id_impresa, 'valido' => true]);
        $s = $this->segnalazione(4, ['id_appalto' => $appalto->id_appalto]);

        $this->actingAs($this->gestore)->post(route('segnalazioni.richiesta-informazioni', $s), [
            'testo' => 'Quale aula?', 'giorni' => 10, 'chiudi_senza_risposta' => '1',
        ])->assertRedirect();

        $s->refresh();
        $this->assertSame(SegnalazioneStato::ATTESA_RISPOSTA, $s->statoEnum());
        $this->assertSame($appalto->id_appalto, (int) $s->id_appalto);
        $r = $s->richiestaAperta;
        $this->assertSame(4, $r->stato_precedente);
        $this->assertTrue($r->chiudi_senza_risposta);
        $termine = CalendarioUfficio::daImpostazioni()->aggiungiGiorniLavorativi(now(), 10);
        $this->assertSame($termine->format('Y-m-d H:i'), $r->termine_at->format('Y-m-d H:i'));
        $nota = $s->note()->where('origine', NotaSegnalazione::ORIGINE_RICHIESTA)->first();
        $this->assertTrue((bool) $nota->visibile_web);
        $this->assertStringContainsString('Quale aula?', $nota->testo);
        Notification::assertSentTo($this->autore, RichiestaInformazioniNotification::class);
    }

    public function test_policy_esclude_stati_5_e_chiusi_autore_api_e_seconda_richiesta(): void
    {
        $this->assertTrue($this->gestore->can('chiediInformazioni', $this->segnalazione(2)));
        $this->assertFalse($this->gestore->can('chiediInformazioni', $this->segnalazione(5)));
        $this->assertFalse($this->gestore->can('chiediInformazioni', $this->segnalazione(7, ['data_chiusura' => now()])));

        $api = User::factory()->create(['auth_source' => 'locale']);
        $this->assertFalse($this->gestore->can('chiediInformazioni', $this->segnalazione(2, ['id_utente_segnalazione' => $api->id])));

        $s = $this->segnalazione(2);
        $this->chiedi($s);
        $this->assertFalse($this->gestore->can('chiediInformazioni', $s->fresh()));
    }

    public function test_risposta_da_nota_riporta_allo_stato_precedente_e_avvisa_gestore(): void
    {
        Notification::fake();
        $s = $this->segnalazione(3);
        $this->chiedi($s);
        $this->travel(2)->hours();

        $this->actingAs($this->autore)->post(route('segnalazioni.nota', $s), ['testo' => 'Aula 12'])->assertRedirect();

        $s->refresh();
        $this->assertSame(SegnalazioneStato::ASSEGNATA_OPERATORE, $s->statoEnum());
        $r = RichiestaInformazioni::where('id_segnalazione', $s->id_segnalazione)->first();
        $this->assertSame(RichiestaInformazioni::ESITO_RISPOSTA, $r->esito);
        $this->assertNotNull($r->id_nota_risposta);
        $this->assertSame($this->autore->id, (int) $s->storicoStati()->first()->id_utente);
        Notification::assertSentTo($this->gestore, RispostaInformazioniNotification::class);
    }

    public function test_risposta_da_allegato(): void
    {
        $s = $this->segnalazione(2);
        $this->chiedi($s);

        AllegatoSegnalazione::create([
            'id_segnalazione' => $s->id_segnalazione, 'percorso' => 'x.jpg', 'tipo' => 'image/jpeg',
            'nome_originale' => 'x.jpg', 'dimensione' => 1, 'id_utente_creazione' => $this->autore->id,
        ]);

        $this->assertSame(SegnalazioneStato::IN_CARICO, $s->fresh()->statoEnum());
    }

    public function test_risposta_da_aggiornamento_form(): void
    {
        $s = $this->segnalazione(2);
        $this->chiedi($s);

        $this->actingAs($this->autore)->post(route('segnalazioni.aggiornamento', $s), ['testo' => 'È nell\'aula 12'])->assertRedirect();

        $this->assertSame(SegnalazioneStato::IN_CARICO, $s->fresh()->statoEnum());
    }

    public function test_risposta_di_aderente_conta(): void
    {
        $s = $this->segnalazione(2);
        $aderente = User::factory()->create(['auth_source' => 'ldap']);
        $aderente->assignRole('segnalatore');
        $s->adesioni()->create(['id_utente' => $aderente->id]);
        $this->chiedi($s);

        $fatta = app(RichiesteInformazioni::class)->registraRisposta($s->fresh(), $aderente);

        $this->assertTrue($fatta);
        $this->assertSame(SegnalazioneStato::IN_CARICO, $s->fresh()->statoEnum());
    }

    public function test_nota_del_gestore_non_conta_come_risposta(): void
    {
        $s = $this->segnalazione(2);
        $this->chiedi($s);

        $this->actingAs($this->gestore)->post(route('segnalazioni.nota', $s), ['testo' => 'Promemoria interno'])->assertRedirect();

        $this->assertSame(SegnalazioneStato::ATTESA_RISPOSTA, $s->fresh()->statoEnum());
        $this->assertNotNull($s->fresh()->richiestaAperta);
    }

    public function test_ritira(): void
    {
        $s = $this->segnalazione(3);
        $this->chiedi($s);

        $this->actingAs($this->gestore)->delete(route('segnalazioni.richiesta-informazioni.ritira', $s))->assertRedirect();

        $s->refresh();
        $this->assertSame(SegnalazioneStato::ASSEGNATA_OPERATORE, $s->statoEnum());
        $this->assertSame(RichiestaInformazioni::ESITO_RITIRATA, RichiestaInformazioni::first()->esito);
        $this->assertNull($s->richiestaAperta);
    }

    public function test_scadenza_manuale_spostata_una_volta(): void
    {
        $calendario = CalendarioUfficio::daImpostazioni();
        $fissata = $calendario->aggiungiGiorniLavorativi(now(), 5);
        $s = $this->segnalazione(2, ['scadenza_manuale' => true, 'data_scadenza_sla' => $fissata]);
        $this->chiedi($s);
        $this->travelTo($calendario->aggiungiOreLavorative(now(), 6));

        app(RichiesteInformazioni::class)->registraRisposta($s->fresh(), $this->autore);
        app(RichiesteInformazioni::class)->registraRisposta($s->fresh(), $this->autore);

        $attesa = $calendario->aggiungiOreLavorative($fissata, 6);
        $this->assertSame($attesa->format('Y-m-d H:i'), $s->fresh()->data_scadenza_sla->format('Y-m-d H:i'));
        $this->assertTrue((bool) $s->fresh()->scadenza_manuale);
    }

    /** Va al momento (chiusura d'ufficio) dell'N-esimo giorno lavorativo dalla richiesta. */
    private function vaAlGiorno(int $n): void
    {
        $this->travelTo(CalendarioUfficio::daImpostazioni()->aggiungiGiorniLavorativi(now(), $n));
    }

    public function test_promemoria_una_volta_sola_a_due_giorni_lavorativi(): void
    {
        $s = $this->segnalazione(2);
        $this->chiedi($s);
        Notification::fake();
        $this->vaAlGiorno(8);

        $this->artisan('segnalazioni:richieste')->assertSuccessful();
        $this->artisan('segnalazioni:richieste')->assertSuccessful();

        Notification::assertSentToTimes($this->autore, RichiestaInformazioniNotification::class, 1);
        $this->assertNotNull(RichiestaInformazioni::first()->promemoria_at);
    }

    public function test_nessun_promemoria_prima(): void
    {
        $s = $this->segnalazione(2);
        $this->chiedi($s);
        Notification::fake();
        $this->vaAlGiorno(7);

        $this->artisan('segnalazioni:richieste')->assertSuccessful();

        Notification::assertNothingSentTo($this->autore);
    }

    public function test_chiusura_automatica_con_motivo_e_sistema(): void
    {
        $s = $this->segnalazione(3);
        $this->chiedi($s);
        Notification::fake();
        $this->vaAlGiorno(11);

        $this->artisan('segnalazioni:richieste')->assertSuccessful();

        $s->refresh();
        $this->assertSame(SegnalazioneStato::ANNULLATA, $s->statoEnum());
        $this->assertNotNull($s->data_chiusura);
        $this->assertSame(0, (int) $s->storicoStati()->first()->id_utente);
        $nota = $s->note()->where('origine', NotaSegnalazione::ORIGINE_SENZA_INTERVENTO)->first();
        $this->assertSame('Chiusa senza intervento: Nessuna risposta alla richiesta di informazioni.', $nota->testo);
        $this->assertTrue((bool) $nota->visibile_web);
        $this->assertSame(RichiestaInformazioni::ESITO_CHIUSA, RichiestaInformazioni::first()->esito);
        Notification::assertSentTo($this->autore, SegnalazioneChiusaNotification::class);
    }

    public function test_senza_spunta_torna_al_gestore(): void
    {
        $s = $this->segnalazione(3);
        app(RichiesteInformazioni::class)->chiedi($s, $this->gestore, 'Quale aula?', 10, false);
        Notification::fake();
        $this->vaAlGiorno(11);

        $this->artisan('segnalazioni:richieste')->assertSuccessful();

        $this->assertSame(SegnalazioneStato::ASSEGNATA_OPERATORE, $s->fresh()->statoEnum());
        $this->assertSame(RichiestaInformazioni::ESITO_CHIUSA, RichiestaInformazioni::first()->esito);
        Notification::assertSentTo($this->gestore, RispostaInformazioniNotification::class);
    }

    public function test_risposta_arrivata_non_chiude(): void
    {
        $s = $this->segnalazione(2);
        $this->chiedi($s);
        app(RichiesteInformazioni::class)->registraRisposta($s->fresh(), $this->autore);
        $this->vaAlGiorno(11);

        $this->artisan('segnalazioni:richieste')->assertSuccessful();

        $this->assertSame(SegnalazioneStato::IN_CARICO, $s->fresh()->statoEnum());
    }

    public function test_motivo_nessuna_risposta_non_selezionabile_a_mano(): void
    {
        $s = $this->segnalazione(2);

        $this->actingAs($this->gestore)->post(route('segnalazioni.azione', $s), [
            'id_azione' => Azione::where('codice', 'annulla')->value('id_azione'), 'motivo' => 'nessuna_risposta',
        ])->assertSessionHasErrors('motivo');
        $this->assertArrayNotHasKey('nessuna_risposta', SegnalazioneWorkflowService::MOTIVI_MANUALI);
    }

    public function test_comando_schedulato_alle_0720(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'segnalazioni:richieste'));

        $this->assertNotNull($evento);
        $this->assertSame('20 7 * * *', $evento->expression);
    }

    public function test_riapri_dopo_chiusura_automatica(): void
    {
        $s = $this->segnalazione(2);
        $this->chiedi($s);
        $this->vaAlGiorno(11);
        $this->artisan('segnalazioni:richieste')->assertSuccessful();

        app(SegnalazioneWorkflowService::class)->eseguiAzione($s->fresh(), Azione::where('codice', 'riapri')->value('id_azione'), $this->gestore);

        $this->assertSame(SegnalazioneStato::IN_CARICO, $s->fresh()->statoEnum());
        $this->assertNull($s->fresh()->richiestaAperta);
    }
}
