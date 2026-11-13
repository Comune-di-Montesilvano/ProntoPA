<?php

namespace Tests\Feature;

use App\Jobs\RicalcolaScadenzeSla;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\User;
use App\Services\Segnalazioni\ScadenzaManuale;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Il gestore fissa a mano la scadenza: nessun ricalcolo automatico la tocca (spec §3). */
class ScadenzaManualeTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        foreach (['lun', 'mar', 'mer', 'gio', 'ven'] as $g) {
            Impostazione::set("orario_{$g}", '08:00-14:00');
        }
        Impostazione::set('orario_sab', '');
        Impostazione::set('orario_dom', '');
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00', 'Europe/Rome')); // mercoledì

        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function segnalazione(): Segnalazione
    {
        return Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'livello_priorita' => 2]);
    }

    public function test_imposta_usa_chiusura_ufficio(): void
    {
        $s = $this->segnalazione();

        app(ScadenzaManuale::class)->imposta($s, Carbon::parse('2026-11-16'), $this->gestore); // lunedì

        $s->refresh();
        $this->assertTrue($s->scadenza_manuale);
        $this->assertSame('2026-11-16 14:00', $s->data_scadenza_sla->timezone('Europe/Rome')->format('Y-m-d H:i'));
        $this->assertStringContainsString('Scadenza spostata al 16/11/2026', $s->note()->latest('id_nota')->first()->testo);
    }

    public function test_giorno_chiuso_slitta_al_lavorativo_successivo(): void
    {
        $s = $this->segnalazione();

        app(ScadenzaManuale::class)->imposta($s, Carbon::parse('2026-11-15'), $this->gestore); // domenica

        $this->assertSame('2026-11-16 14:00', $s->refresh()->data_scadenza_sla->timezone('Europe/Rome')->format('Y-m-d H:i'));
    }

    public function test_data_passata_rifiutata(): void
    {
        $this->expectException(ValidationException::class);

        app(ScadenzaManuale::class)->imposta($this->segnalazione(), Carbon::parse('2026-11-10'), $this->gestore);
    }

    public function test_ricalcolo_job_non_tocca_manuale(): void
    {
        $s = $this->segnalazione();
        app(ScadenzaManuale::class)->imposta($s, Carbon::parse('2026-11-16'), $this->gestore);
        $prima = $s->refresh()->data_scadenza_sla;

        RicalcolaScadenzeSla::dispatchSync();

        $this->assertTrue($prima->equalTo($s->refresh()->data_scadenza_sla));
    }

    public function test_cambio_priorita_non_tocca_manuale(): void
    {
        $s = $this->segnalazione();
        app(ScadenzaManuale::class)->imposta($s, Carbon::parse('2026-11-16'), $this->gestore);
        $prima = $s->refresh()->data_scadenza_sla;

        $this->actingAs($this->gestore)
            ->patch(route('segnalazioni.priorita', $s), ['livello_priorita' => 4])
            ->assertSessionHasNoErrors();

        $s->refresh();
        $this->assertSame(4, (int) $s->livello_priorita);
        $this->assertTrue($prima->equalTo($s->data_scadenza_sla));
    }

    public function test_rimuovi_con_scadenza_passata_lascia_avvisare_sla_check(): void
    {
        SlaConfigurazione::create(['livello_priorita' => 2, 'ore_target' => 3, 'ore_warning' => 4, 'unita' => 'giorni', 'descrizione' => 'Media']);
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'livello_priorita' => 2, 'data_segnalazione' => Carbon::parse('2026-10-01 09:00')]);
        app(ScadenzaManuale::class)->imposta($s, Carbon::parse('2026-11-30'), $this->gestore);

        app(ScadenzaManuale::class)->rimuovi($s);

        $s->refresh();
        $this->assertTrue($s->data_scadenza_sla->isPast());
        $this->assertFalse((bool) $s->sla_violato, 'sla:check deve poterla segnare e avvisare');
    }

    public function test_rimuovi_torna_al_calcolo(): void
    {
        SlaConfigurazione::create(['livello_priorita' => 2, 'ore_target' => 3, 'ore_warning' => 4, 'unita' => 'giorni', 'descrizione' => 'Media']);
        $s = $this->segnalazione();
        app(ScadenzaManuale::class)->imposta($s, Carbon::parse('2026-11-30'), $this->gestore);

        app(ScadenzaManuale::class)->rimuovi($s);

        $s->refresh();
        $this->assertFalse($s->scadenza_manuale);
        $this->assertSame('2026-11-16 14:00', $s->data_scadenza_sla->timezone('Europe/Rome')->format('Y-m-d H:i'));
    }
}
