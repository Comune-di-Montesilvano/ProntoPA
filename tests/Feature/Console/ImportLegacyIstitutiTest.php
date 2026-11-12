<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ImportLegacy;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ImportLegacyIstitutiTest extends TestCase
{
    use RefreshDatabase;

    public function test_istituti_importati_hanno_una_categoria(): void
    {
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('legacy');
        Schema::connection('legacy')->create('istituti', function ($t) {
            $t->integer('id_istituto')->primary();
            $t->string('descrizione');
            $t->string('codice_meccanografico')->nullable();
            $t->string('dirigente')->nullable();
            $t->string('email')->nullable();
            $t->string('recapiti')->nullable();
        });
        DB::connection('legacy')->table('istituti')->insert([
            'id_istituto' => 7, 'descrizione' => 'IC Vecchio', 'codice_meccanografico' => 'pemm01',
            'dirigente' => 'Rossi', 'email' => 'ic@example.org', 'recapiti' => '085',
        ]);

        $cmd = new ImportLegacy();
        $cmd->setLaravel($this->app);
        $cmd->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $metodo = new \ReflectionMethod($cmd, 'importIstituti');
        $metodo->invoke($cmd);

        $riga = DB::table('istituti')->where('codice_meccanografico', 'PEMM01')->first();
        $this->assertNotNull($riga);
        $this->assertNotNull($riga->id_categoria);
        $this->assertSame('Scuole', DB::table('categorie_enti')->where('id_categoria', $riga->id_categoria)->value('nome'));
    }
}
