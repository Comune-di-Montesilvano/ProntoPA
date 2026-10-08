<?php

namespace Tests\Feature\Scuole;

use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Services\Scuole\AnagrafeMiur;
use App\Services\Scuole\SedeNonTrovata;
use App\Services\Scuole\SincronizzaScuole;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SincronizzaScuoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->usaFixture(file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')));
    }

    private function usaFixture(string $json): void
    {
        Storage::disk('local')->put(AnagrafeMiur::GREZZO, $json);
        app()->forgetInstance(AnagrafeMiur::class);
        app(AnagrafeMiur::class)->indicizza(AnagrafeMiur::GREZZO);
        app()->forgetInstance(AnagrafeMiur::class);
    }

    private function sync(): SincronizzaScuole
    {
        return app(SincronizzaScuole::class);
    }

    public function test_crea_istituto_e_sedi_selezionate(): void
    {
        $esito = $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015', 'PEAA828033']);

        $istituto = Istituto::conCodice('PEIC828004')->sole();
        $this->assertSame('I. C. I.SILONE-MONTESILVANO', $istituto->descrizione);
        $this->assertSame('PEIC828004@istruzione.it', $istituto->email);
        $this->assertSame('miur', $istituto->fonte_dati);
        $this->assertSame('scuola', $istituto->tipo_ente);
        $this->assertTrue((bool) $istituto->attivo);

        $sm = Plesso::conCodice('PEMM828015')->sole();
        $this->assertSame($istituto->id_istituto, $sm->id_istituto);
        $this->assertSame('S.M. I.SILONE - MONTESILVANO', $sm->nome);
        $this->assertSame('VIA S.GOTTARDO, MONTESILVANO', $sm->indirizzo);
        $this->assertSame('miur', $sm->fonte_dati);

        $this->assertSame(['PEMM828015', 'PEAA828033'], $esito['creati']);
        $this->assertFalse(Plesso::conCodice('PEIC828004')->exists());
    }

    public function test_adotta_istituto_legacy_con_codice_minuscolo(): void
    {
        $legacy = Istituto::create(['descrizione' => 'SILONE vecchio', 'codice_meccanografico' => ' peic828004 ', 'fonte_dati' => 'manuale']);
        $plesso = Plesso::create(['id_istituto' => $legacy->id_istituto, 'nome' => 'Media', 'codice_meccanografico' => 'pemm828015']);

        $esito = $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015']);

        $this->assertSame(1, Istituto::count());
        $this->assertSame('miur', $legacy->fresh()->fonte_dati);
        $this->assertSame('I. C. I.SILONE-MONTESILVANO', $legacy->fresh()->descrizione);
        $this->assertSame('PEMM828015', $plesso->fresh()->codice_meccanografico);
        $this->assertSame(['PEMM828015'], $esito['adottati']);
    }

    public function test_deselezione_non_cancella(): void
    {
        $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015', 'PEAA828033']);
        $infanzia = Plesso::conCodice('PEAA828033')->sole();
        Segnalazione::factory()->create(['id_plesso' => $infanzia->id_plesso]);

        $esito = $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015']);

        $this->assertTrue(Plesso::conCodice('PEAA828033')->exists());
        $this->assertSame([$infanzia->id_plesso], collect($esito['non_selezionati'])->pluck('id_plesso')->all());
        $this->assertSame(['PEMM828015'], $esito['aggiornati']);
    }

    public function test_codice_sede_non_dell_istituto_rifiuta_tutto(): void
    {
        try {
            $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015', 'PEEE037001']);
            $this->fail('Eccezione attesa');
        } catch (SedeNonTrovata) {
        }

        $this->assertSame(0, Istituto::count());
        $this->assertSame(0, Plesso::count());
    }

    public function test_istituto_assente_dall_indice(): void
    {
        $this->expectException(SedeNonTrovata::class);
        $this->sync()->applicaSelezione('PEIC999999', []);
    }

    public function test_riallinea_aggiorna_miur_e_non_tocca_manuali(): void
    {
        $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015']);
        $manuale = Istituto::create(['descrizione' => 'Mio nome', 'codice_meccanografico' => 'PEEE037001', 'fonte_dati' => 'manuale']);

        $nuovo = str_replace(['S.M. I.SILONE - MONTESILVANO', 'D.D. MONTESILVANO'], ['SCUOLA MEDIA SILONE', 'D.D. NUOVO NOME'], file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')));
        $this->usaFixture($nuovo);

        $esito = $this->sync()->riallinea();

        $this->assertSame('SCUOLA MEDIA SILONE', Plesso::conCodice('PEMM828015')->sole()->nome);
        $this->assertSame('Mio nome', $manuale->fresh()->descrizione);
        $this->assertGreaterThanOrEqual(1, $esito['aggiornati']);
    }

    public function test_riallinea_plesso_passato_a_istituto_assente_segnala(): void
    {
        $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015']);
        $plesso = Plesso::conCodice('PEMM828015')->sole();
        $idPrima = $plesso->id_istituto;

        $this->usaFixture(str_replace(
            '"miur:CODICESCUOLA": "PEMM828015", "miur:DENOMINAZIONESCUOLA": "S.M. I.SILONE - MONTESILVANO", "miur:CODICEISTITUTORIFERIMENTO": "PEIC828004"',
            '"miur:CODICESCUOLA": "PEMM828015", "miur:DENOMINAZIONESCUOLA": "S.M. I.SILONE - MONTESILVANO", "miur:CODICEISTITUTORIFERIMENTO": "PEIC77777X"',
            file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json'))
        ));

        $esito = $this->sync()->riallinea();

        $this->assertSame($idPrima, $plesso->fresh()->id_istituto);
        $this->assertSame(['PEMM828015'], $esito['da_verificare']);
    }

    public function test_riallinea_plesso_passato_a_istituto_presente_si_sposta(): void
    {
        $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015']);
        $this->sync()->applicaSelezione('PEEE037001', ['PEEE037001']);
        $dd = Istituto::conCodice('PEEE037001')->sole();

        $this->usaFixture(str_replace(
            '"miur:CODICESCUOLA": "PEMM828015", "miur:DENOMINAZIONESCUOLA": "S.M. I.SILONE - MONTESILVANO", "miur:CODICEISTITUTORIFERIMENTO": "PEIC828004"',
            '"miur:CODICESCUOLA": "PEMM828015", "miur:DENOMINAZIONESCUOLA": "S.M. I.SILONE - MONTESILVANO", "miur:CODICEISTITUTORIFERIMENTO": "PEEE037001"',
            file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json'))
        ));

        $this->sync()->riallinea();

        $this->assertSame($dd->id_istituto, Plesso::conCodice('PEMM828015')->sole()->id_istituto);
    }

    public function test_non_piu_presenti_con_conteggio_segnalazioni(): void
    {
        $this->sync()->applicaSelezione('PEIC828004', ['PEAA828033']);
        Segnalazione::factory()->count(2)->create(['id_plesso' => Plesso::conCodice('PEAA828033')->sole()->id_plesso]);

        $this->usaFixture(json_encode(['@graph' => [[
            'miur:CODICESCUOLA' => 'PEEE037001', 'miur:CODICEISTITUTORIFERIMENTO' => 'PEEE037001',
            'miur:DENOMINAZIONESCUOLA' => 'D.D. MONTESILVANO', 'miur:DESCRIZIONECOMUNE' => 'MONTESILVANO',
        ]]]));

        $mancanti = collect($this->sync()->nonPiuPresenti());

        $this->assertSame(['istituto', 'sede'], $mancanti->pluck('tipo')->sort()->values()->all());
        $this->assertSame(2, $mancanti->firstWhere('tipo', 'sede')['segnalazioni']);
        $this->assertSame(2, $mancanti->firstWhere('tipo', 'istituto')['segnalazioni']);
    }
}
