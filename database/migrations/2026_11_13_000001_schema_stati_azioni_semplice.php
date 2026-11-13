<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema stati/azioni semplificato (spec 2026-11-13 §1): si assegna già da Nuova, "Assegna a me" al posto
 * di "Prendi in carico", Chiudi solo dopo l'assegnazione, "Chiudi senza intervento" al posto di Annulla,
 * archiviazione solo automatica. Le righe esistenti si aggiornano per codice: il seeder fa solo insertOrIgnore.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: list<int>}> */
    public const AZIONI = [
        'prendi_in_carico' => ['Assegna a me', [1, 2, 3, 4, 5]],
        'assegna_operatore' => ['Assegna operatore', [1, 2, 3, 4, 5]],
        'assegna_impresa' => ['Assegna impresa', [1, 2, 3, 4, 5]],
        'sospendi' => ['Sospendi', [2, 3, 4, 5]],
        'chiudi' => ['Chiudi', [2, 3, 4]],
        'annulla' => ['Chiudi senza intervento', [1, 2, 3, 4, 5, 6]],
        'archivia' => ['Archivia', []],
    ];

    /** @var array<int, string> */
    public const STATI = [
        3 => 'Assegnata',
        4 => 'Assegnata a ditta',
        9 => 'Chiusa senza intervento',
    ];

    public function up(): void
    {
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->boolean('scadenza_manuale')->default(false)->after('data_scadenza_sla');
            $table->timestamp('fine_lavori_at')->nullable()->after('scadenza_manuale');
        });

        $this->aggiornaRiferimenti();
    }

    public function aggiornaRiferimenti(): void
    {
        foreach (self::AZIONI as $codice => [$descrizione, $stati]) {
            DB::table('db_azioni')->where('codice', $codice)->update([
                'descrizione' => $descrizione,
                'parametri_filtro' => json_encode(['stati' => $stati]),
            ]);
        }

        foreach (self::STATI as $id => $descrizione) {
            DB::table('db_stato_segnalazioni')->where('id_stato', $id)->update(['descrizione' => $descrizione]);
        }
    }

    /** Valori prima di questa migration (TabelleRiferimentoSeeder 0.9.18). */
    private const AZIONI_PRIMA = [
        'prendi_in_carico' => ['Prendi in carico', [1]],
        'assegna_operatore' => ['Assegna operatore', [2, 6]],
        'assegna_impresa' => ['Assegna impresa', [2, 6]],
        'sospendi' => ['Sospendi', [1, 2, 3, 4, 5]],
        'chiudi' => ['Chiudi', [2, 3, 4, 5]],
        'annulla' => ['Annulla', [1, 2, 3, 4, 5, 6]],
        'archivia' => ['Archivia', [1, 2, 3, 4, 5, 6]],
    ];

    private const STATI_PRIMA = [
        3 => 'Assegnata a operatore',
        4 => 'Assegnata a impresa',
        9 => 'Annullata',
    ];

    public function down(): void
    {
        $this->ripristinaRiferimenti();

        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->dropColumn(['scadenza_manuale', 'fine_lavori_at']);
        });
    }

    public function ripristinaRiferimenti(): void
    {
        foreach (self::AZIONI_PRIMA as $codice => [$descrizione, $stati]) {
            DB::table('db_azioni')->where('codice', $codice)->update([
                'descrizione' => $descrizione,
                'parametri_filtro' => json_encode(['stati' => $stati]),
            ]);
        }

        foreach (self::STATI_PRIMA as $id => $descrizione) {
            DB::table('db_stato_segnalazioni')->where('id_stato', $id)->update(['descrizione' => $descrizione]);
        }
    }
};
