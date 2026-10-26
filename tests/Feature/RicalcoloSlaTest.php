<?php

namespace Tests\Feature;

use App\Jobs\RicalcolaScadenzeSla;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\User;
use App\Services\SlaService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** Ricalcolo delle scadenze delle aperte al cambio di regole SLA, orario o patrono. */
class RicalcoloSlaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00', 'Europe/Rome'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Tutte le chiavi visibili con il valore attuale, come le invia il form. */
    private function formImpostazioni(array $modifiche = []): array
    {
        $valori = Impostazione::whereNotIn('chiave', Impostazione::CHIAVI_INTERNE)
            ->where('chiave', '!=', 'oidc_client_secret')
            ->pluck('valore', 'chiave')->map(fn ($v) => (string) $v)->all();

        return ['impostazioni' => array_merge($valori, $modifiche)];
    }

    public function test_modifica_regola_ricalcola_solo_le_aperte(): void
    {
        $regola = SlaConfigurazione::create(['livello_priorita' => 2, 'ore_target' => 48, 'ore_warning' => 4]);
        $dati = ['livello_priorita' => 2, 'data_segnalazione' => now()];
        $aperta = Segnalazione::factory()->create($dati + ['id_stato_segnalazione' => 2, 'data_scadenza_sla' => now()->addDays(10)]);
        $chiusa = Segnalazione::factory()->create($dati + ['id_stato_segnalazione' => 7, 'data_chiusura' => now(), 'data_scadenza_sla' => now()->addDays(10)]);

        $this->actingAs($this->admin)->put(route('admin.sla.update', $regola->id_sla), [
            'livello_priorita' => 2, 'ore_target' => 2, 'unita' => 'ore', 'ore_warning' => 1,
        ])->assertRedirect();

        $this->assertSame('2026-10-26 12:00', $aperta->fresh()->data_scadenza_sla->setTimezone('Europe/Rome')->format('Y-m-d H:i'));
        $this->assertSame(now()->addDays(10)->format('Y-m-d H:i'), $chiusa->fresh()->data_scadenza_sla->format('Y-m-d H:i'));
    }

    public function test_scadenza_spostata_nel_futuro_azzera_i_flag(): void
    {
        SlaConfigurazione::create(['livello_priorita' => 2, 'ore_target' => 40, 'ore_warning' => 4]);
        $s = Segnalazione::factory()->create([
            'livello_priorita' => 2, 'id_stato_segnalazione' => 2, 'data_segnalazione' => now()->subDay(),
            'data_scadenza_sla' => now()->subHour(), 'sla_violato' => true, 'sla_warning_inviato' => true,
        ]);

        (new RicalcolaScadenzeSla)->handle(app(SlaService::class));

        $s->refresh();
        $this->assertTrue($s->data_scadenza_sla->isFuture());
        $this->assertFalse((bool) $s->sla_violato);
        $this->assertFalse((bool) $s->sla_warning_inviato);
    }

    public function test_a_rischio_dopo_ricalcolo_mantiene_avviso(): void
    {
        SlaConfigurazione::create(['livello_priorita' => 2, 'ore_target' => 2, 'ore_warning' => 4]);
        $s = Segnalazione::factory()->create([
            'livello_priorita' => 2, 'id_stato_segnalazione' => 2, 'data_segnalazione' => now(),
            'data_scadenza_sla' => now()->subHour(), 'sla_violato' => true, 'sla_warning_inviato' => true,
        ]);

        (new RicalcolaScadenzeSla)->handle(app(SlaService::class));

        $s->refresh();
        $this->assertFalse((bool) $s->sla_violato);
        $this->assertTrue((bool) $s->sla_warning_inviato);
    }

    public function test_regola_eliminata_dispatcha_ricalcolo(): void
    {
        Bus::fake();
        $regola = SlaConfigurazione::create(['livello_priorita' => 2, 'ore_target' => 48, 'ore_warning' => 4]);

        $this->actingAs($this->admin)->delete(route('admin.sla.destroy', $regola->id_sla))->assertRedirect();

        Bus::assertDispatched(RicalcolaScadenzeSla::class);
    }

    public function test_orario_invertito_rifiutato(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.impostazioni.update'), $this->formImpostazioni(['orario_lun' => '14:00-08:00']))
            ->assertSessionHasErrors('impostazioni.orario_lun');
    }

    public function test_patrono_inesistente_rifiutato(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.impostazioni.update'), $this->formImpostazioni(['patrono' => '31/02']))
            ->assertSessionHasErrors('impostazioni.patrono');
    }

    public function test_tutti_i_giorni_chiusi_rifiutato(): void
    {
        $vuoti = [];
        foreach (['lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom'] as $g) {
            $vuoti["orario_$g"] = '';
        }

        $this->actingAs($this->admin)->patch(route('admin.impostazioni.update'), $this->formImpostazioni($vuoti))
            ->assertSessionHasErrors('impostazioni.orario_lun');
        $this->assertSame('08:00-14:00', Impostazione::get('orario_lun'));
    }

    public function test_cambio_orario_dispatcha_ricalcolo_salvataggio_identico_no(): void
    {
        Bus::fake();

        $this->actingAs($this->admin)->patch(route('admin.impostazioni.update'), $this->formImpostazioni())
            ->assertSessionHasNoErrors();
        Bus::assertNotDispatched(RicalcolaScadenzeSla::class);

        $this->actingAs($this->admin)->patch(route('admin.impostazioni.update'), $this->formImpostazioni(['patrono' => '13/06']))
            ->assertSessionHasNoErrors();
        Bus::assertDispatched(RicalcolaScadenzeSla::class);
        $this->assertSame('13/06', Impostazione::get('patrono'));
    }

    public function test_pagina_impostazioni_mostra_festivi_dell_anno(): void
    {
        $this->actingAs($this->admin)->get(route('admin.impostazioni.index'))
            ->assertOk()
            ->assertSee("Orario d'ufficio")
            ->assertSee('06/04/2026')
            ->assertSeeInOrder(['orario_lun', 'orario_mar', 'orario_dom', 'patrono']);
    }
}
