<?php

namespace Tests\Feature\Scuole;

use App\Models\CategoriaEnte;
use App\Models\Istituto;
use App\Support\Scuole\CategoriaIniziale;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CategorieEntiMigrazioneTest extends TestCase
{
    use RefreshDatabase;

    public function test_miur_va_in_scuole_statali(): void
    {
        $this->assertSame('Scuole statali', CategoriaIniziale::per('miur', 'Scuola', 'scuola'));
    }

    public function test_migrazione_tiene_tipo_scritto_a_mano(): void
    {
        $this->assertSame('Asilo comunale', CategoriaIniziale::per('manuale', 'Asilo comunale', 'comune'));
        $this->assertSame('Asilo comunale', CategoriaIniziale::per('manuale', '  Asilo   comunale ', 'altro'));
    }

    public function test_raggruppa_ignorando_maiuscole_e_spazi_con_prima_occorrenza(): void
    {
        $nomi = CategoriaIniziale::assegna([
            1 => ['manuale', 'Asilo comunale', 'comune'],
            2 => ['manuale', '  asilo   COMUNALE ', 'altro'],
            3 => ['miur', 'Scuola', 'scuola'],
        ]);

        $this->assertSame('Asilo comunale', $nomi[1]);
        $this->assertSame('Asilo comunale', $nomi[2]);
        $this->assertSame('Scuole statali', $nomi[3]);
    }

    public function test_default_scuola_usa_tipo_ente(): void
    {
        $this->assertSame('Scuole', CategoriaIniziale::per('manuale', 'Scuola', 'scuola'));
        $this->assertSame('Comune', CategoriaIniziale::per(null, null, 'comune'));
        $this->assertSame('ASL', CategoriaIniziale::per(null, null, 'asl'));
        $this->assertSame('Provincia', CategoriaIniziale::per(null, null, 'provincia'));
        $this->assertSame('Regione', CategoriaIniziale::per(null, null, 'regione'));
        $this->assertSame('Altro', CategoriaIniziale::per(null, null, 'altro'));
        $this->assertSame('Altro', CategoriaIniziale::per(null, null, null));
    }

    public function test_scuole_statali_scritto_a_mano_non_duplica_la_categoria_miur(): void
    {
        $nomi = CategoriaIniziale::assegna([
            1 => ['manuale', 'SCUOLE STATALI', 'scuola'],
            2 => ['miur', 'Scuola', 'scuola'],
        ]);

        $this->assertSame(['Scuole statali'], array_values(array_unique($nomi)));
    }

    public function test_trova_o_crea_riusa_la_riga_esistente(): void
    {
        $primo = CategoriaIniziale::trovaOCrea('Nido', 1, false);
        $this->assertSame($primo, CategoriaIniziale::trovaOCrea('Nido', 2, false));
        $this->assertSame(1, CategoriaEnte::where('nome', 'Nido')->count());
        $this->assertSame(2, CategoriaEnte::count()); // Scuole statali + Nido
    }

    public function test_stato_schema_dopo_migrazione(): void
    {
        $this->assertTrue(Schema::hasColumn('istituti', 'id_categoria'));
        $this->assertFalse(Schema::hasColumn('istituti', 'tipo'));
        $this->assertFalse(Schema::hasColumn('istituti', 'tipo_ente'));

        $miur = CategoriaEnte::where('import_miur', true)->get();
        $this->assertCount(1, $miur);
        $this->assertSame('Scuole statali', $miur->first()->nome);
    }

    public function test_id_categoria_obbligatorio(): void
    {
        $this->expectException(QueryException::class);
        Istituto::create(['descrizione' => 'Senza categoria']);
    }

    public function test_per_import_miur_ricrea_se_manca(): void
    {
        CategoriaEnte::query()->update(['import_miur' => false]);
        $cat = CategoriaEnte::perImportMiur();
        $this->assertTrue($cat->import_miur);
        $this->assertSame(1, CategoriaEnte::where('import_miur', true)->count());
    }
}
