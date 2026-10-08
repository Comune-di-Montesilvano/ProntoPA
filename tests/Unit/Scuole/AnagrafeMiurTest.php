<?php

namespace Tests\Unit\Scuole;

use App\Services\Scuole\AnagrafeMiur;
use App\Services\Scuole\FormatoAnagrafeNonValido;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AnagrafeMiurTest extends TestCase
{
    private AnagrafeMiur $anagrafe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::disk('local')->put(AnagrafeMiur::GREZZO, file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')));
        $this->anagrafe = app(AnagrafeMiur::class);
    }

    public function test_indicizza_righe_valide_e_normalizza(): void
    {
        $this->assertFalse($this->anagrafe->haIndice());

        $this->assertSame(7, $this->anagrafe->indicizza(AnagrafeMiur::GREZZO));
        $this->assertTrue($this->anagrafe->haIndice());

        $this->assertSame([
            'codice' => 'PEMM828015',
            'nome' => 'S.M. I.SILONE - MONTESILVANO',
            'codice_istituto' => 'PEIC828004',
            'nome_istituto' => 'I. C. I.SILONE-MONTESILVANO',
            'grado' => 'SCUOLA PRIMO GRADO',
            'comune' => 'MONTESILVANO',
            'provincia' => 'PESCARA',
            'indirizzo' => 'VIA S.GOTTARDO',
            'cap' => '65015',
            'email' => 'PEIC828004@istruzione.it',
            'sede_amministrativa' => false,
        ], $this->anagrafe->sede('pemm828015'));
    }

    public function test_sede_amministrativa_solo_per_istituti_non_per_dd(): void
    {
        $this->anagrafe->indicizza(AnagrafeMiur::GREZZO);

        $this->assertTrue($this->anagrafe->sede('PEIC828004')['sede_amministrativa']);
        $this->assertFalse($this->anagrafe->sede('PEEE037001')['sede_amministrativa']);
        $this->assertSame('', $this->anagrafe->sede('PEAA83901B')['email']);
    }

    public function test_istituto_con_email_da_sede_amministrativa_o_dalle_sedi(): void
    {
        $this->anagrafe->indicizza(AnagrafeMiur::GREZZO);

        $silone = $this->anagrafe->istituto('PEIC828004');
        $this->assertSame('PEIC828004@istruzione.it', $silone['email']);
        $this->assertCount(3, $silone['sedi']);

        // Rodari: nessuna riga sede direttivo nel dataset
        $rodari = $this->anagrafe->istituto('PEIC83900E');
        $this->assertSame('I. C. "RODARI" -MONTESILVANO', $rodari['nome']);
        $this->assertSame('PEIC83900E@istruzione.it', $rodari['email']);
        $this->assertCount(2, $rodari['sedi']);

        $this->assertNull($this->anagrafe->istituto('PEIC999999'));
    }

    public function test_cerca_per_comune_restituisce_istituti_con_tutte_le_sedi(): void
    {
        $this->anagrafe->indicizza(AnagrafeMiur::GREZZO);

        $risultati = $this->anagrafe->cerca('', 'montesilvano');
        $codici = array_column($risultati, 'codice');
        sort($codici);
        $this->assertSame(['PEEE037001', 'PEIC828004', 'PEIC83900E', 'PEPS05000V'], $codici);

        // le sedi fuori comune dell'istituto restano nel risultato
        $rodari = collect($risultati)->firstWhere('codice', 'PEIC83900E');
        $this->assertContains('PEAA83901B', array_column($rodari['sedi'], 'codice'));
    }

    public function test_cerca_per_testo_su_nome_o_codice(): void
    {
        $this->anagrafe->indicizza(AnagrafeMiur::GREZZO);

        $this->assertSame(['PEIC828004'], array_column($this->anagrafe->cerca('collemare', null), 'codice'));
        $this->assertSame(['PEPS05000V'], array_column($this->anagrafe->cerca('peps05', null), 'codice'));
        $this->assertSame([], $this->anagrafe->cerca('rodari', 'PESCARA'));
    }

    public function test_formato_non_valido_non_tocca_indice_esistente(): void
    {
        $this->anagrafe->indicizza(AnagrafeMiur::GREZZO);
        $prima = Storage::disk('local')->get(AnagrafeMiur::INDICE);

        Storage::disk('local')->put(AnagrafeMiur::DOWNLOAD, '{"data": [{"foo": "bar"}]}');

        try {
            $this->anagrafe->indicizza(AnagrafeMiur::DOWNLOAD);
            $this->fail('Eccezione attesa');
        } catch (FormatoAnagrafeNonValido) {
        }

        $this->assertSame($prima, Storage::disk('local')->get(AnagrafeMiur::INDICE));
    }

    public function test_json_corrotto_rifiutato(): void
    {
        Storage::disk('local')->put(AnagrafeMiur::DOWNLOAD, '<html>404</html>');

        $this->expectException(FormatoAnagrafeNonValido::class);
        $this->anagrafe->indicizza(AnagrafeMiur::DOWNLOAD);
    }

    public function test_stato_vuoto_poi_merge(): void
    {
        $this->assertSame('vuoto', $this->anagrafe->stato()['stato']);

        $this->anagrafe->salvaStato(['stato' => 'ok', 'url' => 'https://x', 'sedi' => 7]);
        $this->anagrafe->salvaStato(['stato' => 'errore', 'messaggio' => 'boom']);

        $stato = $this->anagrafe->stato();
        $this->assertSame('errore', $stato['stato']);
        $this->assertSame('boom', $stato['messaggio']);
        $this->assertSame('https://x', $stato['url']);
    }

    public function test_scrittura_indice_fallita_non_tocca_indice(): void
    {
        $this->anagrafe->indicizza(AnagrafeMiur::GREZZO);
        $prima = Storage::disk('local')->get(AnagrafeMiur::INDICE);

        // disco pieno: put() del file temporaneo restituisce false ('throw' => false)
        $reale = Storage::disk('local');
        $disco = Mockery::mock($reale);
        $disco->shouldReceive('put')->andReturnUsing(
            fn ($path, $contenuto) => $path === AnagrafeMiur::INDICE.'.tmp' ? false : $reale->put($path, $contenuto)
        );
        Storage::set('local', $disco);

        try {
            app(AnagrafeMiur::class)->indicizza(AnagrafeMiur::GREZZO);
            $this->fail('Eccezione attesa');
        } catch (RuntimeException) {
        }

        $this->assertSame($prima, $reale->get(AnagrafeMiur::INDICE));
    }

    public function test_in_corso_scade_dopo_15_minuti(): void
    {
        $this->assertFalse($this->anagrafe->inCorso());

        $this->anagrafe->salvaStato(['stato' => 'in_corso', 'avviato_at' => now()->subMinutes(5)->toIso8601String()]);
        $this->assertTrue($this->anagrafe->inCorso());

        $this->anagrafe->salvaStato(['avviato_at' => now()->subMinutes(20)->toIso8601String()]);
        $this->assertFalse($this->anagrafe->inCorso());
    }
}
