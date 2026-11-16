<?php

namespace Tests\Unit\Segnalazioni;

use App\Models\Appalto;
use App\Models\Impresa;
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
        // Come a DB in produzione: "Assegna a me" registra id_utente_collegato = 0.
        $this->storico($s, 2, '2026-11-13 08:16:08');
        $this->storico($s, 6, '2026-11-13 14:00:34');
        $this->storico($s, 2, '2026-11-13 14:01:02');
        $this->storico($s, 2, '2026-11-13 14:01:23', $g);

        $c = $this->crono($s, '2026-11-16 10:00:00');

        $this->assertSame(['nuova', 'in-carico'], array_column($c['tratti'], 'classe'));
        $this->assertStringStartsWith('In carico a Anna Gestore · presa in carico ven 13, 08:16', $c['tratti'][1]['fumetto']);
        $this->assertSame(['passaggio', 'pausa'], array_column($c['marcatori'], 'tipo'));
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

    public function test_marcatori_vicini_di_tipo_diverso_su_righe_diverse(): void
    {
        $scuola = User::factory()->create();
        $scuola->assignRole('segnalatore');
        $s = $this->segnalazione('2026-11-10 09:00:00', ['id_utente_segnalazione' => $scuola->id]);
        $this->nota($s, $scuola, '2026-11-12 09:00:00', true, false, NotaSegnalazione::ORIGINE_SOLLECITO);
        $this->nota($s, $this->gestore, '2026-11-12 09:10:00', true);

        $c = $this->crono($s, '2026-11-16 09:00:00');

        $this->assertSame(['risegnalata', 'risposta'], array_column($c['marcatori'], 'tipo'));
        $this->assertSame([0, 1], array_column($c['marcatori'], 'riga'));
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

    private function nota(Segnalazione $s, User $autore, string $quando, bool $web, bool $impresa = false, ?string $origine = null): void
    {
        NotaSegnalazione::create([
            'id_segnalazione' => $s->id_segnalazione, 'testo' => 'x', 'id_utente' => $autore->id,
            'visibile_web' => $web, 'visibile_impresa' => $impresa, 'origine' => $origine,
        ])->forceFill(['data' => $quando])->save();
    }

    private function cronoPer(Segnalazione $s, User $lettore, string $ora): array
    {
        $c = Cronogramma::per($s->fresh(), $lettore, Carbon::parse($ora));
        $this->assertNotNull($c);

        return $c;
    }

    /** @return array{0: User, 1: User, 2: Segnalazione} segnalante, operaio, segnalazione assegnata all'operaio */
    private function scenaScuola(): array
    {
        $scuola = User::factory()->create(['name' => 'Sara Scuola']);
        $scuola->assignRole('segnalatore');
        $operaio = User::factory()->create(['name' => 'Oscar Operaio']);
        $operaio->assignRole('operaio');
        $s = $this->segnalazione('2026-11-10 09:00:00', ['id_stato_segnalazione' => 4, 'id_utente_segnalazione' => $scuola->id]);
        $this->storico($s, 2, '2026-11-11 09:00:00', $this->gestore->id);
        $this->storico($s, 3, '2026-11-12 09:00:00', $operaio->id);
        $this->storico($s, 4, '2026-11-12 15:00:00', $operaio->id);

        return [$scuola, $operaio, $s];
    }

    public function test_segnalante_non_vede_nomi(): void
    {
        [$scuola, , $s] = $this->scenaScuola();
        $this->nota($s, $this->gestore, '2026-11-12 10:00:00', true);

        $c = $this->cronoPer($s, $scuola, '2026-11-13 09:00:00');
        $testi = implode("\n", array_merge(array_column($c['tratti'], 'fumetto'), array_column($c['marcatori'], 'fumetto')));

        $this->assertStringNotContainsString('Anna Gestore', $testi);
        $this->assertStringNotContainsString('Oscar Operaio', $testi);
        $this->assertStringContainsString('Ufficio manutenzioni', $testi);
    }

    public function test_segnalante_vede_titoli_semplici_e_assegnate_fuse(): void
    {
        [$scuola, , $s] = $this->scenaScuola();

        $c = $this->cronoPer($s, $scuola, '2026-11-13 09:00:00');

        $this->assertSame(['Ricevuta', 'Presa in carico', 'Ci stiamo lavorando'], array_column($c['tratti'], 'nome'));
        $this->assertStringStartsWith('Aperta da 3 giorni · presa in carico mercoledì', $c['riassunto']);
    }

    public function test_segnalante_marcatori_solo_note_visibili(): void
    {
        [$scuola, , $s] = $this->scenaScuola();
        $altra = User::factory()->create();
        $altra->assignRole('segnalatore');
        $this->nota($s, $this->gestore, '2026-11-11 10:00:00', true);
        $this->nota($s, $this->gestore, '2026-11-11 16:00:00', false);
        $this->nota($s, $scuola, '2026-11-12 10:00:00', true, false, NotaSegnalazione::ORIGINE_SOLLECITO);
        $this->nota($s, $altra, '2026-11-12 16:00:00', true);

        $c = $this->cronoPer($s, $scuola, '2026-11-13 09:00:00');

        $note = array_values(array_filter($c['marcatori'], fn ($m) => $m['tipo'] !== 'passaggio'));
        $this->assertSame(['risposta', 'risegnalata'], array_column($note, 'tipo'));
        $this->assertStringContainsString('Tu', $note[1]['fumetto']);
    }

    public function test_lavoro_parte_dall_assegnazione(): void
    {
        [, $operaio, $s] = $this->scenaScuola();

        $c = $this->cronoPer($s, $operaio, '2026-11-14 09:00:00');

        $this->assertSame('2026-11-12 09:00', $c['da']->format('Y-m-d H:i'));
        $this->assertStringStartsWith('Assegnata a te da 2 giorni', $c['riassunto']);
        $this->assertEqualsWithDelta($c['ora'], $this->somma($c), 0.0001);
    }

    public function test_ditta_che_ha_perso_il_lavoro_non_vede_oltre_la_storia(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica']);
        $appalto = Appalto::create(['descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $ditta->assignRole('impresa');
        $operaio = User::factory()->create();
        $s = $this->segnalazione('2026-11-10 09:00:00', ['id_stato_segnalazione' => 3]);
        StoricoStatoSegnalazione::create([
            'id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => 4, 'id_utente' => $this->gestore->id,
            'id_utente_collegato' => 0, 'id_appalto' => $appalto->id_appalto,
        ])->forceFill(['data_registrazione' => '2026-11-11 09:00:00'])->save();
        $this->storico($s, 3, '2026-11-12 09:00:00', $operaio->id);
        $this->nota($s, $this->gestore, '2026-11-10 12:00:00', false, true);
        $this->nota($s, $this->gestore, '2026-11-11 12:00:00', false, true);
        $this->nota($s, $this->gestore, '2026-11-12 12:00:00', false, false);

        $c = $this->cronoPer($s, $ditta, '2026-11-13 09:00:00');

        $this->assertSame('2026-11-11 09:00', $c['da']->format('Y-m-d H:i'));
        $this->assertCount(1, $c['marcatori']);
        $this->assertStringStartsWith('Assegnata alla tua ditta da 2 giorni', $c['riassunto']);
    }

    public function test_cambio_ora_somma_100(): void
    {
        $s = $this->segnalazione('2026-10-24 09:00:00', ['id_stato_segnalazione' => 2]);
        $this->storico($s, 2, '2026-10-25 12:00:00', $this->gestore->id);

        $this->assertEqualsWithDelta(100.0, $this->somma($this->crono($s, '2026-10-27 09:00:00')), 0.0001);
    }

    /** Richiesta aperta il 13/11 alle 10:00 da Anna Gestore, termine 27/11 18:00. */
    private function richiestaAperta(Segnalazione $s, bool $chiudi = true, string $testo = 'Quale aula?'): \App\Models\RichiestaInformazioni
    {
        $r = \App\Models\RichiestaInformazioni::create([
            'id_segnalazione' => $s->id_segnalazione, 'id_utente' => $this->gestore->id, 'testo' => $testo,
            'stato_precedente' => 2, 'termine_at' => '2026-11-27 18:00:00', 'chiudi_senza_risposta' => $chiudi,
        ]);
        $r->forceFill(['created_at' => '2026-11-13 10:00:00'])->save();

        return $r;
    }

    public function test_cronogramma_tratto_attesa_con_chi_ha_chiesto(): void
    {
        $s = $this->segnalazione('2026-11-12 09:00:00', ['id_stato_segnalazione' => 11]);
        $this->storico($s, 2, '2026-11-12 10:00:00', $this->gestore->id);
        $this->storico($s, 11, '2026-11-13 10:00:00');
        $this->richiestaAperta($s);

        $tratto = collect($this->crono($s, '2026-11-16 10:00:00')['tratti'])->last();

        $this->assertSame('attesa', $tratto['classe']);
        $this->assertStringContainsString('Chiesto da Anna Gestore', $tratto['fumetto']);
        $this->assertStringContainsString('«Quale aula?»', $tratto['fumetto']);
        $this->assertStringContainsString('termine ven 27', $tratto['fumetto']);
    }

    // ── 1.0.3: segnalazioni lunghe ───────────────────────────────────────────

    /** Come lo screenshot: 41 giorni nuova, un mese in carico, assegnata da 3 giorni. */
    private function lunga(): Segnalazione
    {
        $operaio = User::factory()->create(['name' => 'Fabrizio Conte']);
        $s = $this->segnalazione('2026-09-02 09:00:00', ['id_stato_segnalazione' => 3, 'data_scadenza_sla' => '2026-09-16 14:00:00']);
        $this->storico($s, 2, '2026-10-13 09:00:00');
        $this->storico($s, 3, '2026-11-13 10:00:00', $operaio->id);

        return $s;
    }

    public function test_arco_lungo_senza_fasce_dei_giorni_chiusi(): void
    {
        $c = $this->crono($this->lunga(), '2026-11-16 12:00:00');

        $this->assertSame([], $c['chiusi']);
        $this->assertNotContains('chiuso', array_keys($c['legenda']));
        $this->assertStringContainsString('di fine settimana', $c['riassunto']);
    }

    public function test_scala_reale_anche_con_attesa_lunga(): void
    {
        $c = $this->crono($this->lunga(), '2026-11-16 12:00:00');

        // 41 giorni su 75: la scala resta uniforme, niente compressione.
        $this->assertEqualsWithDelta(41 / 75.125 * 100, $c['tratti'][0]['larghezza'], 0.5);
        $this->assertArrayNotHasKey('compresso', $c['tratti'][0]);
    }

    public function test_passaggi_sulla_barra_con_etichette_degli_ultimi(): void
    {
        $c = $this->crono($this->lunga(), '2026-11-16 12:00:00');

        $this->assertSame(['2/9 aperta', '13/10 presa in carico', '13/11 assegnata a F. Conte'], array_column($c['eventi'], 'testo'));
        $this->assertEqualsWithDelta(0.0, $c['eventi'][0]['posizione'], 0.0001);
        $this->assertContains('passaggio', array_column($c['marcatori'], 'tipo'));
        $this->assertEqualsWithDelta($c['tratti'][2]['sinistra'], $c['eventi'][2]['posizione'], 0.0001);
    }
}
