<?php

namespace Tests\Feature\Backup;

use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Backup\DumpDatabase;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DumpDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private array $dirs = [];

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir().'/dump-'.uniqid('', true);
        mkdir($dir, 0777, true);

        return $this->dirs[] = $dir;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    public function test_esporta_e_reimporta_identico(): void
    {
        User::factory()->count(3)->create();
        Segnalazione::factory()->create(['testo_segnalazione' => "Perdita d'acqua — aula 3 ☂"]);
        $dir = $this->tmpDir();
        $utenti = DB::table('users')->count();
        $conteggi = app(DumpDatabase::class)->esporta($dir);
        $prima = DB::table('segnalazioni')->get()->toArray();

        $this->assertSame($utenti, $conteggi['users']);
        $this->assertArrayNotHasKey('migrations', $conteggi);
        $this->assertArrayNotHasKey('sessions', $conteggi);

        // RefreshDatabase tiene una transazione: su sqlite il PRAGMA foreign_keys lì è ignorato.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        Schema::disableForeignKeyConstraints();
        foreach (array_keys($conteggi) as $t) {
            DB::table($t)->delete();
        }
        app(DumpDatabase::class)->importa($dir, $conteggi);

        $this->assertEquals($prima, DB::table('segnalazioni')->get()->toArray());
        $this->assertSame($utenti, DB::table('users')->count());
    }

    public function test_importa_rifiuta_conteggi_diversi(): void
    {
        User::factory()->count(3)->create();
        $dir = $this->tmpDir();
        $conteggi = app(DumpDatabase::class)->esporta($dir);
        $conteggi['users'] = 5;

        DB::table('users')->delete();
        $this->expectException(RuntimeException::class);
        app(DumpDatabase::class)->importa($dir, $conteggi);
    }

    public function test_migrazioni_applicate_in_ordine(): void
    {
        $m = app(DumpDatabase::class)->migrazioniApplicate();
        $this->assertSame(DB::table('migrations')->orderBy('id')->pluck('migration')->all(), $m);
        $this->assertNotEmpty($m);
    }
}
