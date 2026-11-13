<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AllineamentoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 1.0.0: aggiornamento senza ritorno da un database 0.9.20 con dati reali tipici.
 * Su una connessione sqlite a parte (file temporaneo): non tocca il database degli altri test.
 */
class AggiornamentoDa0920Test extends TestCase
{
    private string $file;

    private string $cartella;

    private ?string $connessionePrima = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = storage_path('framework/testing/aggiornamento-'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($this->file));
        touch($this->file);
        config(['database.connections.aggiornamento' => array_merge(config('database.connections.sqlite'), ['database' => $this->file])]);
        $this->connessionePrima = config('database.default');
        config(['database.default' => 'aggiornamento']);
        DB::purge('aggiornamento');

        // Solo le migration fino alla 0.9.20 (ultima: 2026_11_14_000001).
        $this->cartella = storage_path('framework/testing/migrazioni-0920-'.uniqid());
        File::ensureDirectoryExists($this->cartella);
        foreach (File::files(database_path('migrations')) as $f) {
            if (strcmp($f->getFilename(), '2026_11_14_000001_togli_sospensione.php') <= 0) {
                File::copy($f->getPathname(), $this->cartella.'/'.$f->getFilename());
            }
        }
    }

    protected function tearDown(): void
    {
        DB::disconnect('aggiornamento');
        config(['database.default' => $this->connessionePrima]);
        File::delete($this->file);
        File::deleteDirectory($this->cartella);
        parent::tearDown();
    }

    public function test_aggiornamento_da_schema_0920_con_dati(): void
    {
        Artisan::call('migrate', ['--path' => $this->cartella, '--realpath' => true, '--force' => true]);
        $this->assertTrue(Schema::hasColumn('segnalazioni', 'id_provenienza'), 'schema 0.9.20');

        $this->datiTipici0920();

        Artisan::call('migrate', ['--force' => true]);
        $this->seed(AllineamentoSeeder::class);
        $this->seed(AllineamentoSeeder::class);

        // Tipologie: nessuna persa, i due "SFALCI E POTATURE" uniti, niente gruppi.
        $this->assertSame([1, 4, 7], DB::table('tipologie_segnalazioni')->orderBy('id_tipologia_segnalazione')->pluck('id_tipologia_segnalazione')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(2, DB::table('segnalazioni')->where('id_tipologia_segnalazione', 4)->count());
        $this->assertFalse(Schema::hasTable('gruppi_segnalazioni'));

        // Appalti: gruppo senza tipologie → "tutte"; gruppo edilizia → le sue tipologie.
        $this->assertTrue((bool) DB::table('appalti')->where('id_appalto', 2)->value('tutte_tipologie'));
        $this->assertSame([1, 7], DB::table('appalti_tipologie')->where('id_appalto', 1)->orderBy('id_tipologia_segnalazione')->pluck('id_tipologia_segnalazione')->map(fn ($v) => (int) $v)->all());

        // Adesioni URP doppie → una; dati del chiamante e provenienza spariti.
        $this->assertSame(1, DB::table('adesioni_segnalazioni')->where('id_segnalazione', 1)->count());
        $this->assertFalse(Schema::hasColumn('segnalazioni', 'segnalante'));
        $this->assertFalse(Schema::hasTable('provenienze_segnalazioni'));
        $this->assertSame(0, DB::table('permissions')->where('name', 'segnalazioni.per-conto')->count());

        // Regole: quella con specializzazione diventa tipologia + priorità; il doppione sparisce.
        $this->assertSame(1, DB::table('sla_configurazioni')->where('id_tipologia_segnalazione', 1)->where('livello_priorita', 3)->count());
        $this->assertSame(8, (int) DB::table('sla_configurazioni')->where('id_tipologia_segnalazione', 1)->where('livello_priorita', 3)->value('ore_target'));
        $this->assertSame(1, DB::table('sla_configurazioni')->whereNull('id_tipologia_segnalazione')->where('livello_priorita', 2)->count());
        $this->assertFalse(Schema::hasTable('db_specializzazioni'));

        // Coordinate: la sede prende la mediana delle sue segnalazioni, che le perdono.
        $sede = DB::table('plessi')->where('id_plesso', 1)->first();
        $this->assertEqualsWithDelta(42.3, (float) $sede->latitudine, 0.0001);
        $this->assertEqualsWithDelta(14.4, (float) $sede->longitudine, 0.0001);
        $this->assertFalse(Schema::hasColumn('segnalazioni', 'latitudine'));

        // Azione nuova e app che riparte.
        $this->assertSame(1, DB::table('db_azioni')->where('codice', 'rimanda')->count());
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('segnalazioni.show', 1))->assertOk();
        $this->actingAs($admin)->get(route('segnalazioni.create'))->assertOk();
        $this->actingAs($admin)->get(route('gestione.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('admin.tipologie.index'))->assertOk();
    }

    private function datiTipici0920(): void
    {
        // Prima di AllineamentoSeeder: il seeder 1.0.0 crea le tipologie solo su tabella vuota.
        DB::table('gruppi_segnalazioni')->insertOrIgnore([
            ['id_gruppo' => 1, 'descrizione' => 'EDILIZIA SCOLASTICA', 'icona' => 'fas fa-building', 'tipologia' => 0, 'cittadini' => 0],
            ['id_gruppo' => 2, 'descrizione' => "VIABILITA'", 'icona' => 'fas fa-road', 'tipologia' => 3, 'cittadini' => 1],
            ['id_gruppo' => 4, 'descrizione' => 'VERDE PUBBLICO', 'icona' => 'fas fa-seedling', 'tipologia' => 3, 'cittadini' => 1],
        ]);
        DB::table('tipologie_segnalazioni')->insert([
            ['id_tipologia_segnalazione' => 1, 'descrizione' => 'IMPIANTO ELETTRICO', 'icona' => 'fas fa-bolt', 'id_gruppo' => 1],
            ['id_tipologia_segnalazione' => 4, 'descrizione' => 'SFALCI E POTATURE', 'icona' => 'fab fa-pagelines', 'id_gruppo' => 4],
            ['id_tipologia_segnalazione' => 7, 'descrizione' => 'RETE TELEFONICA / DATI', 'icona' => 'fas fa-globe', 'id_gruppo' => 1],
            ['id_tipologia_segnalazione' => 11, 'descrizione' => 'SFALCI E POTATURE', 'icona' => 'fab fa-pagelines', 'id_gruppo' => 1],
        ]);
        $this->seed(AllineamentoSeeder::class);
        DB::table('provenienze_segnalazioni')->insertOrIgnore([
            ['id_provenienza' => 1, 'descrizione' => 'Interno'],
            ['id_provenienza' => 2, 'descrizione' => 'Scuola'],
            ['id_provenienza' => 3, 'descrizione' => 'URP'],
        ]);
        DB::table('db_specializzazioni')->insertOrIgnore([['id_specializzazione' => 2, 'descrizione' => 'Lavori stradali (OG3)']]);
        DB::table('sla_configurazioni')->insert([
            ['id_tipologia_segnalazione' => 1, 'id_specializzazione' => 2, 'livello_priorita' => 3, 'ore_target' => 8, 'unita' => 'ore', 'ore_warning' => 2],
            ['id_tipologia_segnalazione' => null, 'id_specializzazione' => null, 'livello_priorita' => 2, 'ore_target' => 5, 'unita' => 'giorni', 'ore_warning' => 8],
            ['id_tipologia_segnalazione' => null, 'id_specializzazione' => 2, 'livello_priorita' => 2, 'ore_target' => 3, 'unita' => 'giorni', 'ore_warning' => 8],
        ]);

        $categoria = DB::table('categorie_enti')->value('id_categoria');
        DB::table('istituti')->insert(['id_istituto' => 1, 'descrizione' => 'IC Delfico', 'id_categoria' => $categoria, 'attivo' => true]);
        DB::table('plessi')->insert(['id_plesso' => 1, 'id_istituto' => 1, 'nome' => 'Plesso Delfico', 'indirizzo' => 'Via Roma 1']);

        DB::table('imprese')->insert(['id_impresa' => 1, 'ragione_sociale' => 'Edil Srl']);
        DB::table('appalti')->insert([
            ['id_appalto' => 1, 'id_gruppo' => 1, 'descrizione' => 'Manutenzione scuole', 'id_impresa' => 1, 'valido' => true],
            ['id_appalto' => 2, 'id_gruppo' => 2, 'descrizione' => 'Strade', 'id_impresa' => 1, 'valido' => true],
        ]);

        $urp = User::factory()->create(['id_provenienza' => 3]);
        $permesso = DB::table('permissions')->insertGetId(['name' => 'segnalazioni.per-conto', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('model_has_permissions')->insert(['permission_id' => $permesso, 'model_type' => User::class, 'model_id' => $urp->id]);

        $base = ['id_stato_segnalazione' => 1, 'testo_segnalazione' => 'Guasto', 'data_segnalazione' => now(), 'id_plesso' => 1, 'id_utente_segnalazione' => $urp->id, 'id_provenienza' => 3];
        DB::table('segnalazioni')->insert([
            $base + ['id_segnalazione' => 1, 'id_tipologia_segnalazione' => 11, 'latitudine' => 42.2, 'longitudine' => 14.3, 'segnalante' => 'Mario Rossi', 'email' => 'm@example.com', 'id_specializzazione' => 2],
            $base + ['id_segnalazione' => 2, 'id_tipologia_segnalazione' => 4, 'latitudine' => 42.4, 'longitudine' => 14.5, 'segnalante' => null, 'email' => null, 'id_specializzazione' => null],
        ]);
        DB::table('adesioni_segnalazioni')->insert([
            ['id_segnalazione' => 1, 'id_utente' => $urp->id, 'segnalante' => 'Primo chiamante', 'created_at' => now()],
            ['id_segnalazione' => 1, 'id_utente' => $urp->id, 'segnalante' => 'Secondo chiamante', 'created_at' => now()],
        ]);
    }
}
