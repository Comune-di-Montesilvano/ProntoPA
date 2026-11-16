<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Jobs\RicalcolaScadenzeSla;
use App\Models\RichiestaInformazioni;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use App\Services\Sla\CalendarioUfficio;
use App\Services\SlaService;
use App\Support\Segnalazioni\Scadenza;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Richiesta di informazioni a chi ha segnalato: stato 11 e scadenza ferma durante l'attesa. */
class RichiestaInformazioniTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00', 'Europe/Rome'));
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
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
}
