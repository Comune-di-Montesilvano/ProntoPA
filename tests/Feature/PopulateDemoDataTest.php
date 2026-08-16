<?php

namespace Tests\Feature;

use App\Models\AdesioneSegnalazione;
use App\Models\Impresa;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PopulateDemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(ImpostazioniSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_richiede_tabelle_di_riferimento_gia_seedate(): void
    {
        \App\Models\TipologiaSegnalazione::query()->delete();
        \App\Models\Provenienza::query()->delete();

        $this->artisan('demo')->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_crea_dati_demo_completi(): void
    {
        $this->artisan('demo')->assertExitCode(0);

        $this->assertSame(4, Istituto::count());
        $this->assertSame(8, Plesso::count());
        $this->assertSame(2, Impresa::count());
        $this->assertSame(1, Squadra::count());
        $this->assertSame(9, User::where('email', 'like', '%@demo.prontopa.it')->count());
        $this->assertSame(53, Segnalazione::count());
        $this->assertSame(2, AdesioneSegnalazione::count());
        $this->assertGreaterThan(0, StoricoStatoSegnalazione::count());

        $gestore = User::where('username', 'gestore')->first();
        $this->assertTrue($gestore->hasRole('gestore'));
        $this->assertTrue($gestore->supervisore_segnalazioni === false);

        $supervisore = User::where('username', 'supervisore')->first();
        $this->assertTrue($supervisore->supervisore_segnalazioni);
    }

    public function test_rilanciabile_senza_accumulo(): void
    {
        $this->artisan('demo')->assertExitCode(0);
        $this->artisan('demo')->assertExitCode(0);

        $this->assertSame(4, Istituto::count());
        $this->assertSame(53, Segnalazione::count());
        $this->assertSame(9, User::where('email', 'like', '%@demo.prontopa.it')->count());
    }

    public function test_rifiuta_produzione_senza_force(): void
    {
        $this->app->instance('env', 'production');

        $this->artisan('demo')->assertExitCode(1);

        $this->assertDatabaseCount('segnalazioni', 0);
    }
}
