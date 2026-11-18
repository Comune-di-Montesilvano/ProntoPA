<?php

namespace Tests\Feature\Admin;

use App\Jobs\ScaricaAnagrafeMiur;
use App\Models\CategoriaEnte;
use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\User;
use App\Services\Admin\Riquadro;
use App\Services\Admin\StatoSistema;
use App\Services\Scuole\AnagrafeMiur;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 1.0.16: sedi senza recapiti segnalate in Stato del sistema; anagrafe MIUR riscaricata ogni mese. */
class RecapitiEMiurMensileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Storage::fake('local');
        Cache::flush();
    }

    private function riquadro(string $chiave): ?Riquadro
    {
        return collect(app(StatoSistema::class)->riquadri())->firstWhere('chiave', $chiave);
    }

    private function sede(string $nome, ?string $recapiti, bool $enteAttivo = true): Plesso
    {
        $ente = Istituto::create(['descrizione' => 'IC '.$nome, 'id_categoria' => CategoriaEnte::factory()->create()->id_categoria, 'attivo' => $enteAttivo]);

        return Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => $nome, 'recapiti' => $recapiti]);
    }

    public function test_riquadro_recapiti_conta_le_sedi_attive_senza_telefono(): void
    {
        $this->sede('Rodari', '085 123456');
        $this->assertSame(Riquadro::OK, $this->riquadro('recapiti')->stato);

        $this->sede('Collodi', null);
        $this->sede('Pascoli', '  ');
        $this->sede('Spenta', null, enteAttivo: false);

        $r = $this->riquadro('recapiti');
        $this->assertSame(Riquadro::ATTENZIONE, $r->stato);
        $this->assertStringContainsString('2 sedi senza recapiti', $r->messaggio);
        $this->assertStringContainsString('senza_recapiti=1', $r->url);
    }

    public function test_elenco_enti_filtra_le_sedi_senza_recapiti(): void
    {
        $admin = User::factory()->create(['amministratore' => true, 'attivo' => true]);
        $admin->syncRoles(['admin']);
        $this->sede('Rodari', '085 123456');
        $this->sede('Collodi', null);

        $this->actingAs($admin)->get(route('admin.enti.index', ['senza_recapiti' => 1]))
            ->assertOk()->assertSee('IC Collodi')->assertDontSee('IC Rodari');
    }

    public function test_comando_mensile_avvia_lo_scarico_solo_con_il_link(): void
    {
        Queue::fake();
        Impostazione::set('miur_anagrafe_url', '');
        Plesso::create(['id_istituto' => $this->sede('Rodari', null)->id_istituto, 'nome' => 'Da MIUR', 'fonte_dati' => 'miur']);

        $this->artisan('miur:scarica')->assertSuccessful();
        Queue::assertNothingPushed();

        Impostazione::set('miur_anagrafe_url', 'https://dati.istruzione.it/anagrafe.json');
        $this->artisan('miur:scarica')->assertSuccessful();
        Queue::assertPushed(ScaricaAnagrafeMiur::class, 1);

        // Già in corso: niente secondo download.
        $this->artisan('miur:scarica')->assertSuccessful();
        Queue::assertPushed(ScaricaAnagrafeMiur::class, 1);
    }

    public function test_senza_sedi_importate_da_miur_niente_scarico_mensile(): void
    {
        Queue::fake();
        $this->sede('A mano', '085 1');

        $this->artisan('miur:scarica')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_link_di_un_anno_scolastico_passato_segnalato(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::create(2027, 11, 20));
        Impostazione::set('miur_anagrafe_url', 'https://dati.istruzione.it/opendata/opendata/catalogo/elements1/SCUANAGRAFESTAT20252620250901.json');
        app(AnagrafeMiur::class)->salvaStato(['stato' => 'ok', 'scaricato_at' => now()->subDays(5)->toIso8601String()]);

        $r = $this->riquadro('miur');
        $this->assertSame(Riquadro::ATTENZIONE, $r->stato);
        $this->assertStringContainsString('2025/26', $r->messaggio);

        // Nell'anno scolastico del file (e fino a ottobre dell'anno dopo, quando esce il nuovo) è a posto.
        $this->travelTo(\Illuminate\Support\Carbon::create(2026, 10, 20));
        app(AnagrafeMiur::class)->salvaStato(['stato' => 'ok', 'scaricato_at' => now()->subDays(5)->toIso8601String()]);
        $this->assertSame(Riquadro::OK, $this->riquadro('miur')->stato);
    }

    public function test_scarico_programmato_ogni_mese(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'miur:scarica'));

        $this->assertNotNull($evento);
        $this->assertSame('0 4 1 * *', $evento->expression);
    }

    public function test_riquadro_miur_con_scarico_mensile(): void
    {
        Impostazione::set('miur_anagrafe_url', 'https://dati.istruzione.it/anagrafe.json');
        $miur = app(AnagrafeMiur::class);

        $miur->salvaStato(['stato' => 'ok', 'scaricato_at' => now()->subDays(20)->toIso8601String()]);
        $this->assertSame(Riquadro::OK, $this->riquadro('miur')->stato);

        // Lo scarico mensile non è riuscito per più di un mese e mezzo.
        $miur->salvaStato(['stato' => 'ok', 'scaricato_at' => now()->subDays(50)->toIso8601String()]);
        $this->assertSame(Riquadro::ATTENZIONE, $this->riquadro('miur')->stato);

        $miur->salvaStato(['stato' => 'errore', 'messaggio' => 'download non riuscito (HTTP 503)', 'scaricato_at' => now()->subDays(20)->toIso8601String()]);
        $r = $this->riquadro('miur');
        $this->assertSame(Riquadro::PROBLEMA, $r->stato);
        $this->assertStringContainsString('HTTP 503', $r->messaggio);
    }
}
