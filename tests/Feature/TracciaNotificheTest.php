<?php

namespace Tests\Feature;

use App\Models\Appalto;
use App\Models\AvvisoInAttesa;
use App\Models\Impresa;
use App\Models\NotificaSegnalazione;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\User;
use App\Notifications\SollecitoNotification;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Ogni notifica che riguarda una segnalazione resta tracciata e si vede nella scheda del comune. */
class TracciaNotificheTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $segnalatore;

    private User $ditta;

    private Segnalazione $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00', 'Europe/Rome'));

        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true, 'name' => 'Anna Gestore']);
        $this->gestore->assignRole('gestore');
        $this->segnalatore = User::factory()->create(['name' => 'Giulia Ferri']);
        $this->segnalatore->assignRole('segnalatore');
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica', 'email' => 'ufficio@ediladriatica.it']);
        $appalto = Appalto::create(['descrizione' => 'Edile', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $this->ditta = User::factory()->create(['name' => 'Marco Ditta', 'auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $this->ditta->assignRole('impresa');

        $this->s = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $this->segnalatore->id,
            'id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto,
            'data_scadenza_sla' => now()->subDay(), 'livello_priorita' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Il sollecito non parte subito: resta in attesa del riepilogo, una riga per destinatario. */
    public function test_sollecito_in_attesa_per_utente_e_email_aziendale(): void
    {
        $this->actingAs($this->gestore)->post(route('segnalazioni.sollecita', $this->s), ['testo' => 'aggiornare'])->assertRedirect();

        $righe = AvvisoInAttesa::where('id_segnalazione', $this->s->id_segnalazione)->where('tipo', 'SollecitoNotification')->get();
        $this->assertTrue($righe->contains(fn ($r) => $r->destinatario_tipo === 'utente' && (int) $r->id_utente === $this->ditta->id));
        $this->assertTrue($righe->contains(fn ($r) => $r->destinatario_tipo === 'email' && $r->email === 'ufficio@ediladriatica.it'));
        $this->assertSame(1, $righe->where('id_utente', $this->ditta->id)->count(), 'una riga sola: listener registrato una volta');
        $this->assertFalse(NotificaSegnalazione::where('tipo', 'SollecitoNotification')->exists(), 'non ancora inviato');
    }

    public function test_riepilogo_inattivita_tracciato_su_ogni_segnalazione(): void
    {
        SlaConfigurazione::create(['livello_priorita' => 2, 'ore_target' => 10, 'unita' => 'giorni', 'ore_warning' => 8, 'giorni_avviso_inattivita' => 15]);
        $this->s->forceFill(['data_segnalazione' => now()->subDays(40)])->save();

        $this->artisan('segnalazioni:inattivita')->expectsOutputToContain('1 avvisate')->assertSuccessful();

        // Il riepilogo ai supervisori parte subito; l'avviso di inattività aspetta il riepilogo avvisi.
        $tipi = NotificaSegnalazione::where('id_segnalazione', $this->s->id_segnalazione)->pluck('tipo');
        $this->assertContains('RiepilogoInattivitaNotification', $tipi);
        $this->assertTrue(AvvisoInAttesa::where('id_segnalazione', $this->s->id_segnalazione)->where('tipo', 'InattivitaNotification')->exists());
    }

    public function test_scheda_comune_mostra_notifiche_segnalante_e_ditta_no(): void
    {
        // Come al reinvio dal riepilogo: parte subito e viene tracciato.
        $sollecito = new SollecitoNotification($this->s, 'aggiornare');
        $sollecito->daRiepilogo = true;
        $this->ditta->notify($sollecito);
        Notification::route('mail', 'ufficio@ediladriatica.it')->notify($sollecito);

        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertSee('Avvisi inviati')
            ->assertSee('Sollecito')
            ->assertSee('Marco Ditta')
            ->assertSee('ufficio@ediladriatica.it');

        foreach ([$this->segnalatore, $this->ditta] as $utente) {
            $this->actingAs($utente)->get(route('segnalazioni.show', $this->s))
                ->assertOk()
                ->assertDontSee('Avvisi inviati')
                ->assertDontSee('ufficio@ediladriatica.it');
        }
    }

    public function test_scheda_senza_notifiche_lo_dice(): void
    {
        // Toglie l'avviso "nuova segnalazione" mandato al supervisore alla creazione.
        \App\Models\NotificaSegnalazione::query()->delete();

        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertSee('Nessun avviso inviato');
    }
}
