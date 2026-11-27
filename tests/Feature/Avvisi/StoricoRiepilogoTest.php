<?php

namespace Tests\Feature\Avvisi;

use App\Models\Appalto;
use App\Models\AvvisoInAttesa;
use App\Models\Impresa;
use App\Models\Impostazione;
use App\Models\NotificaSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Support\Avvisi\ProssimoGiro;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Cosa si vede nella scheda: avvisi partiti nel riepilogo e avvisi ancora in attesa. */
class StoricoRiepilogoTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $ditta;

    private Segnalazione $a;

    private Segnalazione $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
        Cache::flush();
        Mail::fake();
        // Martedì 10 novembre 2026, 10:00: ufficio aperto 08:00-17:00.
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00', 'Europe/Rome'));
        Impostazione::set('orario_mar', '08:00-17:00');
        Impostazione::set('orario_mer', '08:00-14:00');
        Impostazione::set('digest_ora', '07:30');

        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true, 'name' => 'Anna Gestore']);
        $this->gestore->assignRole('gestore');
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica', 'email' => 'ufficio@ediladriatica.it']);
        $appalto = Appalto::create(['descrizione' => 'Edile', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $this->ditta = User::factory()->create(['name' => 'Marco Ditta', 'auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $this->ditta->assignRole('impresa');
        $autore = User::factory()->create();
        $autore->assignRole('segnalatore');

        foreach (['a', 'b'] as $k) {
            $this->$k = Segnalazione::factory()->create([
                'id_utente_segnalazione' => $autore->id, 'id_stato_segnalazione' => 4,
                'id_appalto' => $appalto->id_appalto, 'data_scadenza_sla' => now()->subDay(), 'livello_priorita' => 2,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sollecita(Segnalazione $s): void
    {
        $this->actingAs($this->gestore)->post(route('segnalazioni.sollecita', $s), ['testo' => 'aggiornare'])->assertRedirect();
    }

    public function test_riepilogo_traccia_tipo_originale(): void
    {
        $this->sollecita($this->a);
        $this->sollecita($this->b);
        $this->assertFalse(NotificaSegnalazione::where('tipo', 'SollecitoNotification')->exists(), 'prima del giro non è partito nulla');

        $this->artisan('avvisi:invia', ['--forza' => true])->assertSuccessful();

        foreach ([$this->a, $this->b] as $s) {
            $riga = NotificaSegnalazione::where('id_segnalazione', $s->id_segnalazione)
                ->where('tipo', 'SollecitoNotification')->where('id_utente', $this->ditta->id)->first();
            $this->assertNotNull($riga, 'riga con il tipo originale, non RiepilogoAvvisiNotification');
            $this->assertSame('mail:riepilogo', $riga->canale);
            $this->assertSame('email (nel riepilogo)', $riga->canaleLeggibile());
        }
        $this->assertFalse(NotificaSegnalazione::where('tipo', 'RiepilogoAvvisiNotification')->exists());
    }

    public function test_scheda_mostra_avviso_in_attesa(): void
    {
        $this->sollecita($this->a);

        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $this->a))
            ->assertOk()
            ->assertSee('Sollecito')
            ->assertSee('in attesa, parte con il riepilogo delle 11:00', false);

        // Partito: non è più in attesa.
        $this->artisan('avvisi:invia', ['--forza' => true])->assertSuccessful();
        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $this->a))
            ->assertOk()->assertDontSee('riepilogo delle 11:00');
    }

    public function test_ritmo_giorno_parte_domattina_o_nel_prossimo_giorno_lavorativo(): void
    {
        $giri = ProssimoGiro::daImpostazioni();

        // Martedì 10:00 → mercoledì 11 alle 07:30.
        $this->assertSame('2026-11-11 07:30', $giri->mattino(now())->format('Y-m-d H:i'));

        // Venerdì sera → lunedì (sabato e domenica chiusi).
        $this->assertSame('2026-11-16 07:30', $giri->mattino(Carbon::parse('2026-11-13 15:00', 'Europe/Rome'))->format('Y-m-d H:i'));
    }

    public function test_ritmo_ora_prossima_ora_piena_chiusura_e_giorno_dopo(): void
    {
        $giri = ProssimoGiro::daImpostazioni();

        $this->assertSame('11:00', $giri->orario(Carbon::parse('2026-11-10 10:00', 'Europe/Rome'))->format('H:i'));
        $this->assertSame('11:00', $giri->orario(Carbon::parse('2026-11-10 10:20', 'Europe/Rome'))->format('H:i'));
        // Chiusura 17:00 è già un'ora piena; dopo la chiusura si riparte dall'apertura del giorno dopo.
        $this->assertSame('2026-11-11 08:00', $giri->orario(Carbon::parse('2026-11-10 17:00', 'Europe/Rome'))->format('Y-m-d H:i'));

        // Chiusura non a ora piena: portata al quarto d'ora.
        Impostazione::set('orario_mar', '08:00-16:20');
        $this->assertSame('16:30', ProssimoGiro::daImpostazioni()->orario(Carbon::parse('2026-11-10 16:05', 'Europe/Rome'))->format('H:i'));
    }

    public function test_etichetta_di_un_giorno_con_ritmo_giorno(): void
    {
        AvvisoInAttesa::create([
            'id_notifica' => 'x1', 'destinatario_tipo' => 'utente', 'id_utente' => $this->gestore->id, 'email' => '',
            'categoria' => 'scadenze', 'ritmo' => 'giorno', 'id_segnalazione' => $this->a->id_segnalazione,
            'tipo' => 'SlaWarningNotification', 'notifica' => 'x',
        ]);

        $elenco = NotificaSegnalazione::inAttesaPer($this->a);

        $voce = collect($elenco)->firstWhere('etichetta', 'A rischio');
        $this->assertNotNull($voce);
        $this->assertSame('in attesa, parte con il riepilogo di domattina', $voce['quando']);
    }
}
