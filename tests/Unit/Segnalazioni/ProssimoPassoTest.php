<?php

namespace Tests\Unit\Segnalazioni;

use App\Models\AllegatoSegnalazione;
use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Support\Segnalazioni\ProssimoPasso;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Il riquadro "prossimo passo" della scheda: cosa serve ora, per chi guarda, con le sole azioni eseguibili. */
class ProssimoPassoTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
    }

    /** @return list<string> */
    private function etichette(array $passo): array
    {
        return array_column($passo['azioni'], 'etichetta');
    }

    private function primaria(array $passo): ?string
    {
        foreach ($passo['azioni'] as $a) {
            if ($a['primaria']) {
                return $a['etichetta'];
            }
        }

        return null;
    }

    private function ditta(string $nome = 'Edil Adriatica'): Appalto
    {
        $impresa = Impresa::create(['ragione_sociale' => $nome]);

        return Appalto::create(['id_gruppo' => 1, 'descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
    }

    public function test_gestore_nuova_prendi_in_carico(): void
    {
        $passo = ProssimoPasso::per(Segnalazione::factory()->create(['id_stato_segnalazione' => 1]), $this->gestore);

        $this->assertSame('Da smistare: conferma priorità e assegna', $passo['titolo']);
        $this->assertSame('Prendi in carico', $this->primaria($passo));
        $this->assertContains('Accorpa a…', $this->etichette($passo));
        $this->assertSame('primary', $passo['tono']);
    }

    public function test_gestore_in_carico_assegna(): void
    {
        $passo = ProssimoPasso::per(Segnalazione::factory()->create(['id_stato_segnalazione' => 2]), $this->gestore);

        $this->assertSame('Da assegnare', $passo['titolo']);
        $this->assertSame('Assegna…', $this->primaria($passo));
    }

    public function test_gestore_operaio_in_ritardo(): void
    {
        $operaio = User::factory()->create(['name' => 'Paolo Neri']);
        $operaio->assignRole('operaio');
        $s = Segnalazione::factory()->create([
            'id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $operaio->id,
            'data_scadenza_sla' => now()->subDays(2)->subHour(),
        ]);

        $passo = ProssimoPasso::per($s, $this->gestore);

        $this->assertSame('rose', $passo['tono']);
        $this->assertStringContainsString('Paolo Neri deve chiudere il lavoro', $passo['titolo']);
        $this->assertStringContainsString('è in ritardo di 2 giorni', $passo['titolo'].' '.$passo['sottotitolo']);
        $this->assertContains('Sollecita', $this->etichette($passo));
        $this->assertContains('Sospendi', $this->etichette($passo));
    }

    public function test_gestore_assegnata_impresa(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $this->ditta()->id_appalto]);

        $passo = ProssimoPasso::per($s, $this->gestore);

        $this->assertSame('Edil Adriatica deve chiudere il lavoro', $passo['titolo']);
        $this->assertContains('Sollecita', $this->etichette($passo));
    }

    public function test_gestore_dopo_fine_lavori_chiude(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $this->ditta()->id_appalto]);
        AllegatoSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'percorso' => 'x.jpg', 'tipo' => 'image/jpeg', 'nome_originale' => 'x.jpg', 'dimensione' => 1, 'id_utente_creazione' => $this->gestore->id, 'fase' => 'dopo']);

        $passo = ProssimoPasso::per($s, $this->gestore);

        $this->assertStringContainsString('Edil Adriatica ha comunicato la fine dei lavori', $passo['titolo']);
        $this->assertSame('Chiudi', $this->primaria($passo));
    }

    public function test_gestore_preventivo(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 5, 'importo_preventivo' => 1250.5, 'id_appalto' => $this->ditta()->id_appalto]);

        $passo = ProssimoPasso::per($s, $this->gestore);

        $this->assertSame('Preventivo di € 1.250,50 da valutare', $passo['titolo']);
        $this->assertSame(['Accetta', 'Rifiuta'], $this->etichette($passo));
    }

    public function test_gestore_sospesa(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 6]);
        StoricoStatoSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => 6, 'id_utente' => $this->gestore->id, 'id_utente_collegato' => 0, 'id_appalto' => 0])
            ->forceFill(['data_registrazione' => '2026-10-20 10:00'])->save();

        $passo = ProssimoPasso::per($s, $this->gestore);

        $this->assertSame('Sospesa dal 20/10', $passo['titolo']);
        $this->assertSame('Riprendi', $this->primaria($passo));
    }

    public function test_gestore_completata(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 7, 'data_chiusura' => '2026-10-21 12:00']);

        $passo = ProssimoPasso::per($s, $this->gestore);

        $this->assertSame('Chiusa il 21/10', $passo['titolo']);
        $this->assertSame('slate', $passo['tono']);
    }

    public function test_ditta_assegnata_fine_lavori_primaria(): void
    {
        $appalto = $this->ditta();
        $ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $appalto->id_impresa]);
        $ditta->assignRole('impresa');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);

        $passo = ProssimoPasso::per($s, $ditta);

        $this->assertSame('Quando hai finito, comunica la fine dei lavori con le foto', $passo['titolo']);
        $this->assertSame('Comunica fine lavori', $this->primaria($passo));
        $this->assertContains('Scrivi al comune', $this->etichette($passo));
    }

    public function test_ditta_dopo_fine_lavori_attende(): void
    {
        $appalto = $this->ditta();
        $ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $appalto->id_impresa]);
        $ditta->assignRole('impresa');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);
        AllegatoSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'percorso' => 'x.jpg', 'tipo' => 'image/jpeg', 'nome_originale' => 'x.jpg', 'dimensione' => 1, 'id_utente_creazione' => $ditta->id, 'fase' => 'dopo']);

        $passo = ProssimoPasso::per($s, $ditta);

        $this->assertStringStartsWith('Fine lavori comunicata il ', $passo['titolo']);
        $this->assertStringEndsWith('in attesa di verifica del comune', $passo['titolo']);
        $this->assertNotContains('Comunica fine lavori', $this->etichette($passo));
    }

    public function test_segnalante_frase_semplice(): void
    {
        $segnalatore = User::factory()->create();
        $segnalatore->assignRole('segnalatore');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'id_utente_segnalazione' => $segnalatore->id]);

        $passo = ProssimoPasso::per($s, $segnalatore);

        $this->assertSame($s->statoEnum()->fraseSegnalante()['titolo'], $passo['titolo']);
        $this->assertSame([], $passo['azioni']);
    }

    public function test_sollecitata_prefisso_e_alza_priorita(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);
        $s->adesioni()->create(['id_utente' => $this->gestore->id]);
        $s->adesioni()->create(['id_utente' => $this->gestore->id]);

        $passo = ProssimoPasso::per($s, $this->gestore);

        $this->assertStringStartsWith('Segnalata 3 volte · ', $passo['titolo']);
        $this->assertContains('Alza priorità', $this->etichette($passo));
    }

    public function test_gestore_non_assegnato_nessuna_azione(): void
    {
        $altro = User::factory()->create(['supervisore_segnalazioni' => false]);
        $altro->assignRole('gestore');

        $passo = ProssimoPasso::per(Segnalazione::factory()->create(['id_stato_segnalazione' => 2]), $altro);

        $this->assertSame([], $passo['azioni']);
    }
}
