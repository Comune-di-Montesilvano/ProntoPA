<?php

namespace Tests\Feature;

use App\Jobs\GeocodificaSedi;
use App\Models\CategoriaEnte;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Services\Sedi\Geocoding;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** 1.0.2: le sedi trovano la posizione da sole, senza che nessuno lanci niente. */
class GeocodificaAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    private Istituto $ente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->ente = Istituto::create(['id_categoria' => CategoriaEnte::factory()->create()->id_categoria, 'descrizione' => 'IC Prova', 'attivo' => true]);
        Sleep::fake();
    }

    private function sede(array $attr = []): Plesso
    {
        return Plesso::create($attr + ['id_istituto' => $this->ente->id_istituto, 'nome' => 'Sede '.uniqid(), 'indirizzo' => 'Via Roma 1']);
    }

    public function test_comando_non_accoda_senza_sedi_mai_tentate(): void
    {
        Queue::fake();
        $this->sede(['latitudine' => 42.5, 'longitudine' => 14.1]);
        $this->sede()->forceFill(['geocodifica_tentata_at' => now()])->save();
        $this->sede(['indirizzo' => '']);

        $this->artisan('sedi:geocodifica')->assertSuccessful();

        Queue::assertNotPushed(GeocodificaSedi::class);
    }

    public function test_comando_accoda_con_una_sede_mai_tentata(): void
    {
        Queue::fake();
        $this->sede();

        $this->artisan('sedi:geocodifica')->assertSuccessful();

        Queue::assertPushed(GeocodificaSedi::class, 1);
    }

    public function test_job_segna_tentativo_su_trovata_e_non_trovata_non_su_errore(): void
    {
        $trovata = $this->sede(['indirizzo' => 'Via Trovata 1']);
        $nonTrovata = $this->sede(['indirizzo' => 'Via Inesistente 9']);
        $errore = $this->sede(['indirizzo' => 'Via Errore 5']);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()
            ->push([['lat' => '42.5', 'lon' => '14.1']])
            ->push([])
            ->push('giù', 500)]);

        (new GeocodificaSedi)->handle(app(Geocoding::class));

        $this->assertNotNull($trovata->fresh()->geocodifica_tentata_at);
        $this->assertEqualsWithDelta(42.5, (float) $trovata->fresh()->latitudine, 0.0001);
        $this->assertNotNull($nonTrovata->fresh()->geocodifica_tentata_at);
        $this->assertNull($errore->fresh()->geocodifica_tentata_at);
    }

    public function test_job_salta_sedi_gia_tentate(): void
    {
        $this->sede()->forceFill(['geocodifica_tentata_at' => now()->subDay()])->save();
        Http::fake();

        (new GeocodificaSedi)->handle(app(Geocoding::class));

        Http::assertNothingSent();
    }

    public function test_comando_schedulato_alle_0610(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'sedi:geocodifica'));

        $this->assertNotNull($evento);
        $this->assertSame('10 6 * * *', $evento->expression);
    }
}
