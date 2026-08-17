<?php

namespace Tests\Feature;

use App\Jobs\ScansionaAllegato;
use App\Models\AllegatoSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\ClamAvService;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScansionaAllegatoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        \Illuminate\Support\Facades\Cache::flush();
        Storage::fake('local');
        Storage::fake('quarantena');
    }

    private function creaAllegato(): AllegatoSegnalazione
    {
        $segnalazione = Segnalazione::factory()->create();
        Storage::disk('local')->put('allegati/1/foo.jpg', 'contenuto finto');

        return AllegatoSegnalazione::create([
            'id_segnalazione'     => $segnalazione->id_segnalazione,
            'percorso'            => 'allegati/1/foo.jpg',
            'tipo'                => 'image/jpeg',
            'nome_originale'      => 'foo.jpg',
            'dimensione'          => 16,
            'id_utente_creazione' => User::factory()->create()->id,
        ]);
    }

    public function test_dispatch_al_create_del_model(): void
    {
        Queue::fake();

        $allegato = $this->creaAllegato();

        Queue::assertPushed(
            ScansionaAllegato::class,
            fn ($job) => $job->idAllegato === $allegato->id_allegato
        );
    }

    public function test_resta_in_attesa_se_antivirus_disattivato(): void
    {
        Impostazione::set('antivirus_enabled', false);
        $allegato = $this->creaAllegatoSenzaDispatch();

        (new ScansionaAllegato($allegato->id_allegato))->handle(app(ClamAvService::class));

        $this->assertSame('in_attesa', $allegato->fresh()->stato_scansione);
    }

    public function test_marca_pulito_se_clamav_risponde_ok(): void
    {
        Impostazione::set('antivirus_enabled', true);
        $this->mock(ClamAvService::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('scanPath')->andReturn(true);
        });

        $allegato = $this->creaAllegatoSenzaDispatch();

        (new ScansionaAllegato($allegato->id_allegato))->handle(app(ClamAvService::class));

        $allegato = $allegato->fresh();
        $this->assertSame('pulito', $allegato->stato_scansione);
        $this->assertNotNull($allegato->scansionato_at);
        Storage::disk('local')->assertExists($allegato->percorso);
    }

    public function test_mette_in_quarantena_se_infetto(): void
    {
        Impostazione::set('antivirus_enabled', true);
        $this->mock(ClamAvService::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('scanPath')->andReturn(false);
        });

        $allegato = $this->creaAllegatoSenzaDispatch();

        (new ScansionaAllegato($allegato->id_allegato))->handle(app(ClamAvService::class));

        $allegato = $allegato->fresh();
        $this->assertSame('infetto', $allegato->stato_scansione);
        Storage::disk('local')->assertMissing($allegato->percorso);
        Storage::disk('quarantena')->assertExists($allegato->percorso);
    }

    public function test_marca_errore_se_clamav_irraggiungibile(): void
    {
        Impostazione::set('antivirus_enabled', true);
        $this->mock(ClamAvService::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('scanPath')->andReturn(null);
        });

        $allegato = $this->creaAllegatoSenzaDispatch();

        (new ScansionaAllegato($allegato->id_allegato))->handle(app(ClamAvService::class));

        $allegato = $allegato->fresh();
        $this->assertSame('errore', $allegato->stato_scansione);
        Storage::disk('local')->assertExists($allegato->percorso);
    }

    public function test_job_su_allegato_gia_eliminato_non_esplode(): void
    {
        $allegato = $this->creaAllegatoSenzaDispatch();
        $id = $allegato->id_allegato;
        $allegato->delete();

        (new ScansionaAllegato($id))->handle(app(ClamAvService::class));

        $this->assertTrue(true); // nessuna eccezione
    }

    /**
     * Come creaAllegato() ma senza lasciare che il dispatch automatico del
     * model event esegua il job reale contro la coda configurata — i test
     * qui vogliono controllare l'esecuzione di handle() a mano.
     */
    private function creaAllegatoSenzaDispatch(): AllegatoSegnalazione
    {
        Queue::fake();

        return $this->creaAllegato();
    }
}
