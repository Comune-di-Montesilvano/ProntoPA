<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\User;
use App\Services\Sla\CalendarioUfficio;
use App\Services\SlaService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Scadenze SLA contate solo in orario d'ufficio, regole in ore o giorni lavorativi. */
class SlaOrarioUfficioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function creaDaForm(): Segnalazione
    {
        $user = User::factory()->create();
        $user->assignRole('segnalatore');
        $this->actingAs($user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Perdita acqua dal rubinetto',
            'id_provenienza'            => 1,
            'livello_priorita'          => 2,
        ])->assertRedirect();

        return Segnalazione::latest('id_segnalazione')->firstOrFail();
    }

    private function scadenzaLocale(Segnalazione $s): string
    {
        return $s->data_scadenza_sla->copy()->setTimezone('Europe/Rome')->format('Y-m-d H:i');
    }

    public function test_impostazioni_seminate_danno_l_orario_di_default(): void
    {
        $cal = CalendarioUfficio::daImpostazioni();
        $fascia = $cal->fascia(Carbon::parse('2026-10-27', 'Europe/Rome'));
        $this->assertSame('08:00', $fascia[0]->format('H:i'));
        $this->assertSame('17:00', $fascia[1]->format('H:i'));

        Impostazione::set('patrono', '13/06');
        $this->assertTrue(CalendarioUfficio::daImpostazioni()->isFestivo(Carbon::parse('2027-06-14', 'Europe/Rome')->subDay()));
    }

    public function test_regola_in_ore_dal_sabato_scade_lunedi(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 10:00', 'Europe/Rome'));
        SlaConfigurazione::create(['livello_priorita' => 2, 'ore_target' => 6, 'ore_warning' => 2]);

        $this->assertSame('2026-10-26 14:00', $this->scadenzaLocale($this->creaDaForm()));
    }

    public function test_regola_in_giorni_dal_sabato_scade_lunedi_alla_chiusura(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 10:00', 'Europe/Rome'));
        SlaConfigurazione::create(['livello_priorita' => 2, 'ore_target' => 1, 'unita' => 'giorni', 'ore_warning' => 2]);

        $this->assertSame('2026-10-26 14:00', $this->scadenzaLocale($this->creaDaForm()));
    }

    public function test_a_rischio_conta_ore_lavorative(): void
    {
        // Venerdì 13:00, scadenza lunedì 10:00: 60 ore di calendario ma 3 lavorative.
        Carbon::setTestNow(Carbon::parse('2026-10-30 13:00', 'Europe/Rome'));
        SlaConfigurazione::create(['livello_priorita' => 2, 'ore_target' => 48, 'ore_warning' => 4]);
        $s = Segnalazione::factory()->create([
            'livello_priorita' => 2, 'id_stato_segnalazione' => 2,
            'data_scadenza_sla' => Carbon::parse('2026-11-02 10:00', 'Europe/Rome'),
        ]);

        $this->assertTrue(app(SlaService::class)->isArischio($s));

        $s->update(['data_scadenza_sla' => Carbon::parse('2026-11-03 10:00', 'Europe/Rome')]);
        $this->assertFalse(app(SlaService::class)->isArischio($s->fresh()));
    }

    public function test_admin_salva_regola_in_giorni(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('admin.sla.store'), [
            'livello_priorita' => 3, 'ore_target' => 1, 'unita' => 'giorni', 'ore_warning' => 4,
        ])->assertRedirect(route('admin.sla.index'));

        $this->assertSame('giorni', SlaConfigurazione::firstOrFail()->unita);
        $this->actingAs($admin)->get(route('admin.sla.index'))
            ->assertOk()
            ->assertSee('1 giorno lavorativo');
    }

    public function test_admin_unita_non_valida_rifiutata(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('admin.sla.store'), [
            'livello_priorita' => 3, 'ore_target' => 1, 'unita' => 'settimane', 'ore_warning' => 4,
        ])->assertSessionHasErrors('unita');
    }

    public function test_descrizione_tempo(): void
    {
        $this->assertSame('4 ore lavorative', (new SlaConfigurazione(['ore_target' => 4, 'unita' => 'ore']))->descrizioneTempo());
        $this->assertSame('1 ora lavorativa', (new SlaConfigurazione(['ore_target' => 1, 'unita' => 'ore']))->descrizioneTempo());
        $this->assertSame('1 giorno lavorativo', (new SlaConfigurazione(['ore_target' => 1, 'unita' => 'giorni']))->descrizioneTempo());
        $this->assertSame('3 giorni lavorativi', (new SlaConfigurazione(['ore_target' => 3, 'unita' => 'giorni']))->descrizioneTempo());
    }
}
