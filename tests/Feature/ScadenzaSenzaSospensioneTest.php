<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\NuovaNotaSegnalazione;
use App\Services\SegnalazioneWorkflowService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** 0.9.20: Sospendi tolta, la scadenza si sposta con un motivo che la scuola vede sempre. */
class ScadenzaSenzaSospensioneTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $scuola;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Notification::fake();
        $this->gestore = User::factory()->create(['name' => 'Anna Bianchi', 'supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $this->scuola = User::factory()->create();
        $this->scuola->assignRole('segnalatore');
    }

    private function segnalazione(array $attr = []): Segnalazione
    {
        return Segnalazione::factory()->create(array_merge([
            'id_stato_segnalazione' => 2, 'id_utente_segnalazione' => $this->scuola->id,
            'id_operatore_assegnato' => $this->gestore->id,
        ], $attr));
    }

    public function test_sposta_scadenza_con_motivo_visibile_alla_scuola(): void
    {
        $s = $this->segnalazione();
        $giorno = now()->addDays(5)->format('Y-m-d');

        $this->actingAs($this->gestore)
            ->patch(route('segnalazioni.scadenza', $s), ['data_scadenza' => $giorno, 'motivo' => 'Fibra guasta, si verifica dopo la riparazione'])
            ->assertRedirect(route('segnalazioni.show', $s->id_segnalazione))
            ->assertSessionHas('success');

        $s->refresh();
        $this->assertTrue($s->scadenza_manuale);
        $this->assertSame($giorno, $s->data_scadenza_sla->format('Y-m-d'));
        $nota = $s->note()->latest('id_nota')->first();
        $this->assertTrue((bool) $nota->visibile_web);
        $this->assertStringContainsString('Fibra guasta, si verifica dopo la riparazione', $nota->testo);
        $this->assertStringContainsString(now()->addDays(5)->format('d/m/Y'), $nota->testo);
        Notification::assertSentTo($this->scuola, NuovaNotaSegnalazione::class);
    }

    public function test_motivo_obbligatorio(): void
    {
        $s = $this->segnalazione();

        $this->actingAs($this->gestore)
            ->patch(route('segnalazioni.scadenza', $s), ['data_scadenza' => now()->addDays(2)->format('Y-m-d')])
            ->assertSessionHasErrors('motivo');

        $this->assertFalse((bool) $s->fresh()->scadenza_manuale);
    }

    public function test_solo_chi_gestisce(): void
    {
        $s = $this->segnalazione();

        $this->actingAs($this->scuola)
            ->patch(route('segnalazioni.scadenza', $s), ['data_scadenza' => now()->addDays(2)->format('Y-m-d'), 'motivo' => 'x'])
            ->assertForbidden();
    }

    public function test_sospendi_non_piu_disponibile(): void
    {
        foreach ([2, 3, 4, 5] as $stato) {
            $s = $this->segnalazione(['id_stato_segnalazione' => $stato]);
            $codici = app(SegnalazioneWorkflowService::class)->getAzioniDisponibili($s, $this->gestore)->pluck('codice');
            $this->assertNotContains('sospendi', $codici, "stato {$stato}");
        }
    }

    public function test_scheda_mostra_scadenza_e_non_sospendi(): void
    {
        $s = $this->segnalazione(['id_stato_segnalazione' => 3]);

        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $s))
            ->assertOk()
            ->assertSee('Scadenza…')
            ->assertSee(route('segnalazioni.scadenza', $s), false)
            ->assertDontSee('Motivo della sospensione')
            ->assertDontSee('id="assegna-scadenza"', false);
    }

    public function test_migration_riporta_le_sospese_allo_stato_di_prima(): void
    {
        $s = $this->segnalazione(['id_stato_segnalazione' => 6, 'data_scadenza_sla' => now()->addDays(3)]);
        StoricoStatoSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => 3, 'id_utente' => $this->gestore->id, 'id_utente_collegato' => 0, 'id_appalto' => 0, 'data_registrazione' => now()->subDays(2)]);
        StoricoStatoSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => 6, 'id_utente' => $this->gestore->id, 'id_utente_collegato' => 0, 'id_appalto' => 0, 'data_registrazione' => now()->subDay()]);
        DB::table('db_azioni')->where('codice', 'sospendi')->update(['parametri_filtro' => '{"stati":[2,3,4,5]}']);
        $scadenza = $s->fresh()->data_scadenza_sla->toDateTimeString();

        (require database_path('migrations/2026_11_14_000001_togli_sospensione.php'))->up();

        $s->refresh();
        $this->assertSame(SegnalazioneStato::ASSEGNATA_OPERATORE, $s->statoEnum());
        $this->assertSame($scadenza, $s->data_scadenza_sla->toDateTimeString());
        $this->assertSame('{"stati":[]}', DB::table('db_azioni')->where('codice', 'sospendi')->value('parametri_filtro'));
    }

    public function test_attivita_ripresa_e_tag_ditta_senza_ditta(): void
    {
        $s = $this->segnalazione();
        foreach ([[2, 3], [6, 2], [2, 1]] as [$stato, $giorniFa]) {
            StoricoStatoSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => $stato, 'id_utente' => $this->gestore->id, 'id_utente_collegato' => 0, 'id_appalto' => 0, 'data_registrazione' => now()->subDays($giorniFa)]);
        }
        $s->note()->create(['testo' => 'Nota interna vecchia', 'id_utente' => $this->gestore->id, 'visibile_web' => false, 'visibile_impresa' => true]);

        $voci = \App\Support\Segnalazioni\Attivita::per($s, $this->gestore);

        $this->assertTrue($voci->contains(fn ($v) => ($v['frase'] ?? null) === 'ha ripreso'));
        $this->assertSame('Interna', $voci->first(fn ($v) => ($v['testo'] ?? null) === 'Nota interna vecchia')['tag']);
    }

    public function test_chi_ci_lavora_in_carico_a_gestore(): void
    {
        $s = $this->segnalazione();

        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $s))
            ->assertOk()
            ->assertSee('In carico a</span>', false)
            ->assertDontSee('Operatore</span>', false);
    }
}
