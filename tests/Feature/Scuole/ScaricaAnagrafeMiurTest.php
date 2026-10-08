<?php

namespace Tests\Feature\Scuole;

use App\Jobs\ScaricaAnagrafeMiur;
use App\Models\Plesso;
use App\Services\Scuole\AnagrafeMiur;
use App\Services\Scuole\SincronizzaScuole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

class ScaricaAnagrafeMiurTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://dati.istruzione.it/test/anagrafe.json';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function esegui(): ?Throwable
    {
        try {
            (new ScaricaAnagrafeMiur(self::URL))->handle(app(AnagrafeMiur::class), app(SincronizzaScuole::class));
        } catch (Throwable $e) {
            return $e;
        } finally {
            app()->forgetInstance(AnagrafeMiur::class);
        }

        return null;
    }

    public function test_scarica_indicizza_e_riallinea(): void
    {
        Http::fake([self::URL => Http::response(file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')))]);

        $this->assertNull($this->esegui());

        $anagrafe = app(AnagrafeMiur::class);
        $stato = $anagrafe->stato();
        $this->assertSame('ok', $stato['stato']);
        $this->assertSame(self::URL, $stato['url']);
        $this->assertSame(7, $stato['sedi']);
        $this->assertNotNull($stato['scaricato_at']);
        $this->assertNotNull($anagrafe->sede('PEMM828015'));
        Storage::disk('local')->assertExists(AnagrafeMiur::GREZZO);
        Storage::disk('local')->assertMissing(AnagrafeMiur::DOWNLOAD);
    }

    public function test_riallinea_record_miur_dopo_download(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json'));
        // Http::fake ripetuto non sostituisce lo stub precedente: sequenza.
        Http::fake([self::URL => Http::sequence()
            ->push($fixture)
            ->push(str_replace('S.M. I.SILONE - MONTESILVANO', 'MEDIA SILONE', $fixture))]);
        $this->esegui();
        app(SincronizzaScuole::class)->applicaSelezione('PEIC828004', ['PEMM828015']);

        $this->esegui();

        $this->assertSame('MEDIA SILONE', Plesso::conCodice('PEMM828015')->sole()->nome);
    }

    public function test_404_lascia_indice_precedente(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')))
            ->push('Not Found', 404)]);
        $this->esegui();
        $indice = Storage::disk('local')->get(AnagrafeMiur::INDICE);

        $this->assertNotNull($this->esegui());

        $stato = app(AnagrafeMiur::class)->stato();
        $this->assertSame('errore', $stato['stato']);
        $this->assertStringContainsString('HTTP 404', $stato['messaggio']);
        $this->assertSame(self::URL, $stato['url']); // URL dell'indice ancora in uso
        $this->assertSame($indice, Storage::disk('local')->get(AnagrafeMiur::INDICE));
    }

    public function test_formato_non_valido_segnala_errore(): void
    {
        Http::fake([self::URL => Http::response('<html>manutenzione</html>')]);

        $this->assertNotNull($this->esegui());

        $stato = app(AnagrafeMiur::class)->stato();
        $this->assertSame('errore', $stato['stato']);
        $this->assertStringContainsString('formato del file MIUR', $stato['messaggio']);
        $this->assertFalse(app(AnagrafeMiur::class)->haIndice());
    }
}
