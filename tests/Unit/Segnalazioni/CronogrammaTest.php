<?php

namespace Tests\Unit\Segnalazioni;

use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Support\Segnalazioni\Cronogramma;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** "Com'è andata finora": la barra del tempo della segnalazione, in tempo reale. */
class CronogrammaTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->gestore = User::factory()->create(['name' => 'Anna Gestore', 'supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
    }

    private function segnalazione(string $aperta, array $attr = []): Segnalazione
    {
        return Segnalazione::factory()->create($attr + [
            'data_segnalazione' => $aperta, 'id_stato_segnalazione' => 1, 'data_chiusura' => null,
            'data_scadenza_sla' => null, 'id_operatore_assegnato' => 0,
        ]);
    }

    private function storico(Segnalazione $s, int $stato, string $quando, int $collegato = 0, ?int $autore = null): void
    {
        StoricoStatoSegnalazione::create([
            'id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => $stato,
            'id_utente' => $autore ?? $this->gestore->id, 'id_utente_collegato' => $collegato, 'id_appalto' => 0,
        ])->forceFill(['data_registrazione' => $quando])->save();
    }

    private function crono(Segnalazione $s, string $ora): array
    {
        $c = Cronogramma::per($s->fresh(), $this->gestore, Carbon::parse($ora));
        $this->assertNotNull($c);

        return $c;
    }

    private function somma(array $c): float
    {
        return round(array_sum(array_column($c['tratti'], 'larghezza')), 6);
    }

    public function test_tratti_3579(): void
    {
        $g = $this->gestore->id;
        $s = $this->segnalazione('2026-11-10 09:13:10', ['id_stato_segnalazione' => 2, 'id_operatore_assegnato' => $g, 'data_scadenza_sla' => '2026-11-16 14:00:00']);
        $this->storico($s, 2, '2026-11-13 08:16:08', $g);
        $this->storico($s, 6, '2026-11-13 14:00:34');
        $this->storico($s, 2, '2026-11-13 14:01:02');
        $this->storico($s, 2, '2026-11-13 14:01:23', $g);

        $c = $this->crono($s, '2026-11-16 10:00:00');

        $this->assertSame(['nuova', 'in-carico'], array_column($c['tratti'], 'classe'));
        $this->assertSame(['pausa'], array_column($c['marcatori'], 'tipo'));
        $this->assertEqualsWithDelta($c['ora'], $this->somma($c), 0.0001);
        $this->assertEqualsWithDelta(0.0, $c['tratti'][0]['sinistra'], 0.0001);
        $this->assertSame('3 giorni senza che nessuno la aprisse · 3 giorni in carico (di cui 2 di fine settimana)', $c['riassunto']);
    }

    public function test_fumetto_dice_chi(): void
    {
        $g = $this->gestore->id;
        $s = $this->segnalazione('2026-11-10 09:13:10', ['id_stato_segnalazione' => 2, 'id_operatore_assegnato' => $g]);
        $this->storico($s, 2, '2026-11-13 08:16:08', $g);

        $tratto = $this->crono($s, '2026-11-16 10:00:00')['tratti'][1];

        $this->assertStringContainsString('In carico a Anna Gestore', $tratto['fumetto']);
        $this->assertStringContainsString('presa in carico ven 13, 08:16', $tratto['fumetto']);
    }

    public function test_fumetto_affidata_da(): void
    {
        $collega = User::factory()->create(['name' => 'Bruno Collega']);
        $collega->assignRole('gestore');
        $s = $this->segnalazione('2026-11-10 09:13:10', ['id_stato_segnalazione' => 2, 'id_operatore_assegnato' => $collega->id]);
        $this->storico($s, 2, '2026-11-13 08:16:08', $collega->id);

        $tratto = $this->crono($s, '2026-11-16 10:00:00')['tratti'][1];

        $this->assertStringContainsString('In carico a Bruno Collega', $tratto['fumetto']);
        $this->assertStringContainsString('affidata da Anna Gestore ven 13, 08:16', $tratto['fumetto']);
    }

    public function test_righe_doppie_fuse(): void
    {
        $operaio = User::factory()->create();
        $s = $this->segnalazione('2026-11-10 09:00:00', ['id_stato_segnalazione' => 3]);
        $this->storico($s, 3, '2026-11-11 09:00:00', $operaio->id);
        $this->storico($s, 3, '2026-11-11 09:01:00', $operaio->id);

        $this->assertSame(['nuova', 'assegnata'], array_column($this->crono($s, '2026-11-12 09:00:00')['tratti'], 'classe'));
    }

    public function test_fine_settimana_e_festivo_velati(): void
    {
        $s = $this->segnalazione('2026-12-04 09:00:00');

        $c = $this->crono($s, '2026-12-09 12:00:00');

        $this->assertCount(2, $c['chiusi']);
        $this->assertSame('SABATO E DOMENICA', $c['chiusi'][0]['etichetta']);
        $this->assertSame('FESTIVO', $c['chiusi'][1]['etichetta']);
        $this->assertEqualsWithDelta(100.0, $this->somma($c), 0.0001);
    }

    public function test_scadenza_superata_ha_superata_da(): void
    {
        $s = $this->segnalazione('2026-11-10 09:00:00', ['data_scadenza_sla' => '2026-11-12 14:00:00']);

        $c = $this->crono($s, '2026-11-16 10:00:00');

        $this->assertNotNull($c['superata_da']);
        $this->assertEqualsWithDelta($c['scadenza'], $c['superata_da'], 0.0001);
        $this->assertEqualsWithDelta(100.0, $c['ora'], 0.0001);
    }

    public function test_scadenza_oltre_ora_allunga_intervallo(): void
    {
        $s = $this->segnalazione('2026-11-10 09:00:00', ['data_scadenza_sla' => '2026-11-20 14:00:00']);

        $c = $this->crono($s, '2026-11-16 10:00:00');

        $this->assertSame('2026-11-20 14:00', $c['a']->format('Y-m-d H:i'));
        $this->assertEqualsWithDelta(100.0, $c['scadenza'], 0.0001);
        $this->assertLessThan(100.0, $c['ora']);
        $this->assertNull($c['superata_da']);
    }

    public function test_chiusa_finisce_alla_chiusura(): void
    {
        $s = $this->segnalazione('2026-11-10 09:00:00', ['id_stato_segnalazione' => 7, 'data_chiusura' => '2026-11-13 10:00:00']);
        $this->storico($s, 2, '2026-11-11 09:00:00', $this->gestore->id);
        $this->storico($s, 7, '2026-11-13 10:00:00');

        $c = $this->crono($s, '2026-11-16 10:00:00');

        $this->assertSame('2026-11-13 10:00', $c['a']->format('Y-m-d H:i'));
        $this->assertNull($c['ora']);
        $this->assertSame(['nuova', 'in-carico'], array_column($c['tratti'], 'classe'));
        $this->assertStringStartsWith('Chiusa in 3 giorni', $c['riassunto']);
    }

    public function test_legacy_senza_storico_un_tratto_non_noto(): void
    {
        $s = $this->segnalazione('2026-11-10 09:00:00', ['id_stato_segnalazione' => 7, 'data_chiusura' => '2026-11-13 10:00:00']);

        $c = $this->crono($s, '2026-11-16 10:00:00');

        $this->assertSame(['non-noto'], array_column($c['tratti'], 'classe'));
        $this->assertEqualsWithDelta(100.0, $this->somma($c), 0.0001);
    }

    public function test_tacche_5_30_200_giorni(): void
    {
        $cinque = $this->crono($this->segnalazione('2026-11-10 09:00:00'), '2026-11-15 09:00:00');
        $this->assertCount(6, $cinque['tacche']);

        $trenta = $this->crono($this->segnalazione('2026-10-01 09:00:00'), '2026-10-31 09:00:00');
        $this->assertCount(4, $trenta['tacche']);
        foreach ($trenta['tacche'] as $t) {
            $this->assertStringStartsWith('lun', $t['testo']);
        }

        $duecento = $this->crono($this->segnalazione('2026-01-10 09:00:00'), '2026-07-29 09:00:00');
        $this->assertSame(['feb', 'mar', 'apr', 'mag', 'giu', 'lug'], array_column($duecento['tacche'], 'testo'));
    }

    public function test_marcatori_vicini_raggruppati_e_note_interne_escluse(): void
    {
        $s = $this->segnalazione('2026-11-10 09:00:00');
        foreach (['2026-11-12 09:00:00' => true, '2026-11-12 09:01:00' => true, '2026-11-13 09:00:00' => false] as $quando => $web) {
            NotaSegnalazione::create([
                'id_segnalazione' => $s->id_segnalazione, 'testo' => 'x', 'id_utente' => $this->gestore->id,
                'visibile_web' => $web, 'visibile_impresa' => false,
            ])->forceFill(['data' => $quando])->save();
        }

        $c = $this->crono($s, '2026-11-16 09:00:00');

        $this->assertCount(1, $c['marcatori']);
        $this->assertSame('risposta', $c['marcatori'][0]['tipo']);
        $this->assertSame(2, $c['marcatori'][0]['quanti']);
    }

    public function test_intervallo_nullo_un_tratto_pieno(): void
    {
        $s = $this->segnalazione('2026-11-16 10:00:00');

        $c = $this->crono($s, '2026-11-16 10:00:00');

        $this->assertCount(1, $c['tratti']);
        $this->assertEqualsWithDelta(100.0, $c['tratti'][0]['larghezza'], 0.0001);
    }

    public function test_evento_prima_dell_apertura_portato_all_inizio(): void
    {
        $s = $this->segnalazione('2026-11-10 09:00:00', ['id_stato_segnalazione' => 2]);
        $this->storico($s, 2, '2026-11-09 09:00:00', $this->gestore->id);

        $c = $this->crono($s, '2026-11-12 09:00:00');

        $this->assertSame(['in-carico'], array_column($c['tratti'], 'classe'));
        $this->assertGreaterThanOrEqual(0.0, $c['tratti'][0]['sinistra']);
        $this->assertEqualsWithDelta(100.0, $this->somma($c), 0.0001);
    }

    public function test_cambio_ora_somma_100(): void
    {
        $s = $this->segnalazione('2026-10-24 09:00:00', ['id_stato_segnalazione' => 2]);
        $this->storico($s, 2, '2026-10-25 12:00:00', $this->gestore->id);

        $this->assertEqualsWithDelta(100.0, $this->somma($this->crono($s, '2026-10-27 09:00:00')), 0.0001);
    }
}
