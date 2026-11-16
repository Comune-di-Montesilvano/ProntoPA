<?php

namespace Tests\Unit\Segnalazioni;

use App\Models\AdesioneSegnalazione;
use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Support\Segnalazioni\Fatti;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** I quattro fatti in testa alla scheda gestore: stato, scadenza, priorità, da quanto è aperta. Oggi = lunedì 16/11/2026. */
class FattiTest extends TestCase
{
    use RefreshDatabase;

    private const ORA = '2026-11-16 10:00:00';

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->gestore = User::factory()->create(['name' => 'Anna Gestore', 'supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
    }

    private function segnalazione(array $attr = []): Segnalazione
    {
        return Segnalazione::factory()->create($attr + [
            'data_segnalazione' => '2026-11-10 09:00:00', 'id_stato_segnalazione' => 1, 'data_chiusura' => null,
            'data_scadenza_sla' => null, 'id_operatore_assegnato' => 0, 'scadenza_manuale' => false, 'livello_priorita' => 2,
        ]);
    }

    private function storico(Segnalazione $s, int $stato, string $quando, int $appalto = 0): void
    {
        StoricoStatoSegnalazione::create([
            'id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => $stato,
            'id_utente' => $this->gestore->id, 'id_utente_collegato' => 0, 'id_appalto' => $appalto,
        ])->forceFill(['data_registrazione' => $quando])->save();
    }

    private function fatti(Segnalazione $s): array
    {
        return Fatti::per($s->fresh(), Carbon::parse(self::ORA));
    }

    public function test_nuova(): void
    {
        $f = $this->fatti($this->segnalazione());

        $this->assertSame('Nuova', $f['stato']['valore']);
        $this->assertSame("nessuno l'ha ancora presa", $f['stato']['dettaglio']);
    }

    public function test_in_carico_da_venerdi(): void
    {
        $s = $this->segnalazione(['id_stato_segnalazione' => 2, 'id_operatore_assegnato' => $this->gestore->id]);
        $this->storico($s, 2, '2026-11-13 08:16:08');

        $this->assertSame('a Anna Gestore, da venerdì', $this->fatti($s)['stato']['dettaglio']);
    }

    public function test_a_ditta(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica']);
        $appalto = Appalto::create(['descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $s = $this->segnalazione(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);
        $this->storico($s, 4, '2026-11-14 09:00:00', $appalto->id_appalto);

        $this->assertSame('a Edil Adriatica, da sabato', $this->fatti($s)['stato']['dettaglio']);
    }

    public function test_oltre_sei_giorni_dice_la_data(): void
    {
        $s = $this->segnalazione(['data_segnalazione' => '2026-11-02 09:00:00', 'id_stato_segnalazione' => 2, 'id_operatore_assegnato' => $this->gestore->id]);
        $this->storico($s, 2, '2026-11-03 09:00:00');

        $this->assertSame('a Anna Gestore, dal 3/11', $this->fatti($s)['stato']['dettaglio']);
    }

    public function test_scaduta(): void
    {
        $f = $this->fatti($this->segnalazione(['data_scadenza_sla' => '2026-11-14 10:00:00']));

        $this->assertSame('Scaduta da 2 giorni', $f['scadenza']['valore']);
        $this->assertSame('rose', $f['scadenza']['tono']);
    }

    public function test_a_rischio(): void
    {
        $f = $this->fatti($this->segnalazione(['data_scadenza_sla' => '2026-11-16 14:00:00']));

        $this->assertSame('Oggi alle 14:00', $f['scadenza']['valore']);
        $this->assertSame('mancano 4 ore', $f['scadenza']['dettaglio']);
        $this->assertSame('amber', $f['scadenza']['tono']);
    }

    public function test_manca_una_ora_al_singolare(): void
    {
        $f = $this->fatti($this->segnalazione(['data_scadenza_sla' => '2026-11-16 11:30:00']));

        $this->assertSame('manca 1 ora', $f['scadenza']['dettaglio']);
    }

    public function test_scadenza_manuale(): void
    {
        $f = $this->fatti($this->segnalazione(['data_scadenza_sla' => '2026-11-20 14:00:00', 'scadenza_manuale' => true]));

        $this->assertSame('Venerdì alle 14:00', $f['scadenza']['valore']);
        $this->assertSame('mancano 4 giorni lavorativi · spostata a mano', $f['scadenza']['dettaglio']);
        $this->assertSame('slate', $f['scadenza']['tono']);
    }

    public function test_senza_regola(): void
    {
        $f = $this->fatti($this->segnalazione());

        $this->assertSame('nessuna scadenza', $f['scadenza']['dettaglio']);
        $this->assertNull($f['priorita']['dettaglio']);
        $this->assertSame('Media', $f['priorita']['valore']);
    }

    public function test_priorita_dice_la_regola(): void
    {
        SlaConfigurazione::updateOrCreate(['id_tipologia_segnalazione' => null, 'livello_priorita' => 3], ['ore_target' => 2, 'unita' => 'giorni', 'ore_warning' => 4]);

        $f = $this->fatti($this->segnalazione(['livello_priorita' => 3]));

        $this->assertSame('Alta', $f['priorita']['valore']);
        $this->assertSame('regola: 2 giorni lavorativi', $f['priorita']['dettaglio']);
    }

    public function test_chiusa_in_tempo(): void
    {
        $f = $this->fatti($this->segnalazione(['id_stato_segnalazione' => 7, 'data_chiusura' => '2026-11-13 09:00:00', 'data_scadenza_sla' => '2026-11-14 10:00:00']));

        $this->assertSame('Chiusa in 3 giorni', $f['scadenza']['valore']);
        $this->assertSame('entro la scadenza', $f['scadenza']['dettaglio']);
    }

    public function test_chiusa_in_ritardo(): void
    {
        $f = $this->fatti($this->segnalazione(['id_stato_segnalazione' => 7, 'data_chiusura' => '2026-11-13 10:00:00', 'data_scadenza_sla' => '2026-11-11 10:00:00']));

        $this->assertSame('2 giorni oltre la scadenza', $f['scadenza']['dettaglio']);
    }

    public function test_aperta_oggi(): void
    {
        $this->assertSame('oggi', $this->fatti($this->segnalazione(['data_segnalazione' => '2026-11-16 09:00:00']))['aperta']['valore']);
    }

    public function test_segnalata_due_volte_e_tre_risposte(): void
    {
        $s = $this->segnalazione();
        AdesioneSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'id_utente' => User::factory()->create()->id]);
        foreach ([1, 2, 3] as $i) {
            NotaSegnalazione::create([
                'id_segnalazione' => $s->id_segnalazione, 'testo' => "r$i", 'id_utente' => $this->gestore->id,
                'visibile_web' => true, 'visibile_impresa' => false,
            ]);
        }

        $f = $this->fatti($s);

        $this->assertSame('6 giorni', $f['aperta']['valore']);
        $this->assertSame('segnalata 2 volte · 3 risposte a chi ha segnalato', $f['aperta']['dettaglio']);
    }

    public function test_fatti_scadenza_ferma(): void
    {
        $s = $this->segnalazione(['id_stato_segnalazione' => 11, 'data_scadenza_sla' => '2026-11-12 14:00:00']);
        $this->storico($s, 11, '2026-11-13 10:00:00');

        $f = $this->fatti($s);

        $this->assertSame('In attesa di risposta', $f['stato']['valore']);
        $this->assertSame('Ferma', $f['scadenza']['valore']);
        $this->assertSame('slate', $f['scadenza']['tono']);
    }
}
