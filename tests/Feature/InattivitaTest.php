<?php

namespace Tests\Feature;

use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\User;
use App\Notifications\ArchiviataInattivitaNotification;
use App\Notifications\InattivitaNotification;
use App\Notifications\RiepilogoInattivitaNotification;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Segnalazioni ferme: avviso a chi ha aperto, a chi ha in carico e ai supervisori, poi archiviazione. */
class InattivitaTest extends TestCase
{
    use RefreshDatabase;

    private User $segnalatore;

    private User $ditta;

    private User $supervisore;

    private Appalto $appalto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00', 'Europe/Rome')); // martedì
        Notification::fake();

        $this->segnalatore = User::factory()->create();
        $this->segnalatore->assignRole('segnalatore');
        $this->supervisore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->supervisore->assignRole('gestore');
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica']);
        $this->appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'Edile', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $this->ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $this->ditta->assignRole('impresa');

        SlaConfigurazione::create([
            'livello_priorita' => 2, 'ore_target' => 10, 'unita' => 'giorni', 'ore_warning' => 8,
            'giorni_avviso_inattivita' => 15, 'giorni_archiviazione_inattivita' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ferma(array $attr = []): Segnalazione
    {
        return Segnalazione::factory()->create(array_merge([
            'id_utente_segnalazione' => $this->segnalatore->id,
            'id_stato_segnalazione' => 4, 'id_appalto' => $this->appalto->id_appalto,
            'livello_priorita' => 2, 'data_segnalazione' => now()->subDays(40),
        ], $attr));
    }

    private function esegui(): void
    {
        $this->artisan('segnalazioni:inattivita')->assertSuccessful();
    }

    public function test_ferma_oltre_soglia_avvisa_tutti(): void
    {
        $s = $this->ferma();

        $this->esegui();

        $this->assertNotNull($s->fresh()->avviso_inattivita_at);
        Notification::assertSentTo($this->segnalatore, InattivitaNotification::class);
        Notification::assertSentTo($this->ditta, InattivitaNotification::class);
        Notification::assertSentTo($this->supervisore, RiepilogoInattivitaNotification::class);
        Notification::assertNotSentTo($this->supervisore, InattivitaNotification::class);
    }

    public function test_attivita_recente_nessun_avviso(): void
    {
        $s = $this->ferma();
        $s->note()->create(['testo' => 'Passiamo giovedì', 'id_utente' => $this->ditta->id, 'visibile_web' => true, 'visibile_impresa' => true]);

        $this->esegui();

        $this->assertNull($s->fresh()->avviso_inattivita_at);
        Notification::assertNothingSent();
    }

    public function test_avviso_scaduto_archivia(): void
    {
        $s = $this->ferma(['avviso_inattivita_at' => now()->subDays(30)]);

        $this->esegui();

        $s->refresh();
        $this->assertSame(10, (int) $s->getRawOriginal('id_stato_segnalazione'));
        $this->assertNotNull($s->data_chiusura);
        $this->assertStringContainsString('inattività', $s->note()->latest('id_nota')->value('testo'));
        Notification::assertSentTo($this->segnalatore, ArchiviataInattivitaNotification::class);
        Notification::assertSentTo($this->ditta, ArchiviataInattivitaNotification::class);
        Notification::assertSentTo($this->supervisore, RiepilogoInattivitaNotification::class);
    }

    public function test_scheda_archiviata_dal_sistema_si_apre(): void
    {
        $s = $this->ferma(['avviso_inattivita_at' => now()->subDays(30)]);
        $this->esegui();

        foreach ([$this->segnalatore, $this->supervisore] as $utente) {
            $this->actingAs($utente)->get(route('segnalazioni.show', $s))
                ->assertOk()
                ->assertSee('Archiviata per inattività');
        }
    }

    public function test_avviso_non_ancora_scaduto_non_archivia(): void
    {
        $s = $this->ferma(['avviso_inattivita_at' => now()->subDays(3)]);

        $this->esegui();

        $this->assertNull($s->fresh()->data_chiusura);
        Notification::assertNothingSent();
    }

    public function test_attivita_dopo_avviso_lo_azzera(): void
    {
        $s = $this->ferma(['avviso_inattivita_at' => now()->subDays(30)]);
        $s->note()->create(['testo' => 'Ancora da fare', 'id_utente' => $this->segnalatore->id, 'visibile_web' => true, 'visibile_impresa' => true, 'data' => now()->subDays(25)]);

        $this->esegui();

        $s->refresh();
        $this->assertNull($s->data_chiusura);
        $this->assertNull($s->avviso_inattivita_at);
    }

    public function test_regola_senza_soglie_o_sospesa_ignorate(): void
    {
        SlaConfigurazione::create(['livello_priorita' => 1, 'ore_target' => 30, 'unita' => 'giorni', 'ore_warning' => 8]);
        $bassa = $this->ferma(['livello_priorita' => 1]);
        $sospesa = $this->ferma(['id_stato_segnalazione' => 6]);

        $this->esegui();

        $this->assertNull($bassa->fresh()->avviso_inattivita_at);
        $this->assertNull($sospesa->fresh()->avviso_inattivita_at);
        Notification::assertNothingSent();
    }

    public function test_scheda_mostra_data_di_archiviazione(): void
    {
        $s = $this->ferma(['avviso_inattivita_at' => now()->subDay()]);

        foreach ([$this->segnalatore, $this->supervisore, $this->ditta] as $utente) {
            $this->actingAs($utente)->get(route('segnalazioni.show', $s))
                ->assertOk()
                ->assertSee('verrà archiviata');
        }
    }

    public function test_admin_imposta_soglie(): void
    {
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('admin.sla.store'), [
            'livello_priorita' => 3, 'ore_target' => 3, 'unita' => 'giorni', 'ore_warning' => 7,
            'giorni_avviso_inattivita' => 5, 'giorni_archiviazione_inattivita' => 10,
        ])->assertRedirect(route('admin.sla.index'));

        $r = SlaConfigurazione::where('livello_priorita', 3)->firstOrFail();
        $this->assertSame(5, (int) $r->giorni_avviso_inattivita);
        $this->assertSame(10, (int) $r->giorni_archiviazione_inattivita);
    }
}
