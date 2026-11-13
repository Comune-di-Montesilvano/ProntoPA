<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\Azione;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Schema stati/azioni semplificato (spec 2026-11-13 §1). */
class SchemaStatiAzioniTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, list<int>}> */
    public static function azioni(): array
    {
        return [
            'assegna a me' => ['prendi_in_carico', 'Assegna a me', [1, 2, 3, 4, 5]],
            'assegna operatore' => ['assegna_operatore', 'Assegna operatore', [1, 2, 3, 4, 5]],
            'assegna impresa' => ['assegna_impresa', 'Assegna impresa', [1, 2, 3, 4, 5]],
            'sospendi' => ['sospendi', 'Sospendi', [2, 3, 4, 5]],
            'chiudi' => ['chiudi', 'Chiudi', [2, 3, 4]],
            'chiudi senza intervento' => ['annulla', 'Chiudi senza intervento', [1, 2, 3, 4, 5, 6]],
            'archivia' => ['archivia', 'Archivia', []],
        ];
    }

    /** @param list<int> $stati */
    #[DataProvider('azioni')]
    public function test_filtri_azioni_secondo_schema(string $codice, string $descrizione, array $stati): void
    {
        $this->seed(TabelleRiferimentoSeeder::class);

        $azione = Azione::where('codice', $codice)->firstOrFail();
        $this->assertSame($descrizione, $azione->descrizione);
        $this->assertSame($stati, $azione->parametri_filtro['stati']);
    }

    public function test_etichette_stati(): void
    {
        $this->assertSame('Assegnata', SegnalazioneStato::from(3)->label());
        $this->assertSame('Assegnata a ditta', SegnalazioneStato::from(4)->label());
        $this->assertSame('Chiusa senza intervento', SegnalazioneStato::from(9)->label());
    }

    public function test_down_ripristina_descrizioni_e_filtri(): void
    {
        $this->seed(TabelleRiferimentoSeeder::class);
        $migration = require database_path('migrations/2026_11_13_000001_schema_stati_azioni_semplice.php');

        $migration->ripristinaRiferimenti();

        $this->assertSame('Annulla', Azione::where('codice', 'annulla')->value('descrizione'));
        $this->assertSame([1], Azione::where('codice', 'prendi_in_carico')->firstOrFail()->parametri_filtro['stati']);
        $this->assertSame([2, 3, 4, 5], Azione::where('codice', 'chiudi')->firstOrFail()->parametri_filtro['stati']);
        $this->assertSame('Annullata', DB::table('db_stato_segnalazioni')->where('id_stato', 9)->value('descrizione'));
    }

    public function test_migration_aggiorna_righe_esistenti(): void
    {
        DB::table('db_azioni')->where('codice', 'chiudi')->delete();
        DB::table('db_azioni')->insert([
            'id_azione' => 9, 'codice' => 'chiudi', 'descrizione' => 'Chiudi', 'id_stato_segnalazione' => 7,
            'competenza_azione' => 0, 'colore' => 'success', 'flag_appalto' => 0, 'flag_operatore' => 0,
            'flag_notifica' => 1, 'ordine' => 80, 'parametri_filtro' => '{"stati":[2,3,4,5]}',
        ]);
        DB::table('db_stato_segnalazioni')->where('id_stato', 9)->delete();
        DB::table('db_stato_segnalazioni')->insert(['id_stato' => 9, 'descrizione' => 'Annullata', 'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 1, 'colore_sfondo' => 'danger']);

        $migration = require base_path('database/migrations/2026_11_13_000001_schema_stati_azioni_semplice.php');
        $migration->aggiornaRiferimenti();

        $this->assertSame([2, 3, 4], Azione::where('codice', 'chiudi')->first()->parametri_filtro['stati']);
        $this->assertSame('Chiusa senza intervento', DB::table('db_stato_segnalazioni')->where('id_stato', 9)->value('descrizione'));
    }

    public function test_colonne_nuove(): void
    {
        $this->assertTrue(Schema::hasColumns('segnalazioni', ['scadenza_manuale', 'fine_lavori_at']));
    }
}
