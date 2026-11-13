<?php

namespace Tests\Feature;

use App\Jobs\GeocodificaSedi;
use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Admin\StatoSistema;
use App\Services\Sedi\Geocoding;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** 1.0.0: la posizione sta sulla sede, non sulla segnalazione. */
class CoordinateSediTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Plesso $sede;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->admin = User::factory()->create(['amministratore' => true]);
        $this->admin->assignRole('admin');
        $ente = Istituto::create(['id_categoria' => \App\Models\CategoriaEnte::factory()->create()->id_categoria, 'descrizione' => 'IC Prova', 'attivo' => true]);
        $this->sede = Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => 'Plesso Prova', 'indirizzo' => 'Via Roma 1']);
        Sleep::fake();
    }

    private function nominatimTrova(float $lat = 42.5, float $lng = 14.1): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['lat' => (string) $lat, 'lon' => (string) $lng]])]);
    }

    public function test_trova_dall_indirizzo_usa_comune_e_user_agent(): void
    {
        Impostazione::set('geocoding_comune', 'Montesilvano');
        $this->nominatimTrova();

        $this->actingAs($this->admin)->postJson(route('admin.sedi.geocodifica', $this->sede))
            ->assertOk()->assertJson(['lat' => 42.5, 'lng' => 14.1]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'nominatim.openstreetmap.org/search')
            && ($r->data()['q'] ?? null) === 'Via Roma 1, Montesilvano'
            && str_starts_with($r->header('User-Agent')[0] ?? '', 'ProntoPA'));
        $this->assertNull($this->sede->fresh()->latitudine, 'la sede si salva solo col form');
    }

    public function test_nominatim_giu_non_tocca_la_sede(): void
    {
        $this->sede->update(['latitudine' => null, 'longitudine' => null]);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('errore', 500)]);

        (new GeocodificaSedi)->handle(app(Geocoding::class));

        $this->assertNull($this->sede->fresh()->latitudine);
        $esito = json_decode((string) Impostazione::get('geocoding_esito'), true);
        $this->assertSame(1, $esito['errori']);
        $this->assertSame(0, $esito['trovate']);
    }

    public function test_job_sulla_coda_dei_lavori_lunghi(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('admin.sedi.geocodifica-mancanti'));

        \Illuminate\Support\Facades\Queue::assertPushed(GeocodificaSedi::class,
            fn (GeocodificaSedi $j) => $j->connection === config('backup.connessione_coda') && $j->queue === 'lunghi');
    }

    public function test_job_solo_sedi_senza_coordinate(): void
    {
        $altra = Plesso::create(['id_istituto' => $this->sede->id_istituto, 'nome' => 'Già fatta', 'indirizzo' => 'Via Milano 2', 'latitudine' => 41, 'longitudine' => 13]);
        $this->nominatimTrova();

        (new GeocodificaSedi)->handle(app(Geocoding::class));

        Http::assertSentCount(1);
        $this->assertEqualsWithDelta(42.5, (float) $this->sede->fresh()->latitudine, 0.0001);
        $this->assertEqualsWithDelta(41.0, (float) $altra->fresh()->latitudine, 0.0001);
    }

    public function test_salva_posizione_dal_form_sede(): void
    {
        $this->actingAs($this->admin)->put(route('admin.sedi.update', $this->sede), [
            'id_istituto' => $this->sede->id_istituto, 'nome' => 'Plesso Prova', 'indirizzo' => 'Via Roma 1',
            'latitudine' => '42.4', 'longitudine' => '14.2',
        ])->assertRedirect();

        $this->assertEqualsWithDelta(42.4, (float) $this->sede->fresh()->latitudine, 0.0001);
    }

    public function test_scheda_mostra_mappa_sede_e_link_maps(): void
    {
        $this->sede->update(['latitudine' => 42.4, 'longitudine' => 14.2]);
        $s = Segnalazione::factory()->create(['id_plesso' => $this->sede->id_plesso]);

        $this->actingAs($this->admin)->get(route('segnalazioni.show', $s))
            ->assertOk()
            ->assertSee('id="mappa-segnalazione"', false)
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=42.4,14.2', false);
    }

    public function test_scheda_sede_senza_coordinate_solo_indirizzo(): void
    {
        $s = Segnalazione::factory()->create(['id_plesso' => $this->sede->id_plesso]);

        $this->actingAs($this->admin)->get(route('segnalazioni.show', $s))
            ->assertOk()
            ->assertSee('Via Roma 1')
            ->assertDontSee('id="mappa-segnalazione"', false);
    }

    public function test_stato_sistema_riquadro_sedi_senza_coordinate(): void
    {
        $r = collect(app(StatoSistema::class)->riquadri())->firstWhere('chiave', 'sedi');

        $this->assertNotNull($r);
        $this->assertSame('attenzione', $r->stato);
        $this->assertStringContainsString('1 sede', $r->messaggio);
        Http::assertNothingSent();
    }
}
