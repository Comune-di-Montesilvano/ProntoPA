<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ristruttura il workflow degli stati segnalazioni:
 *
 * - Remap ID stati: da 14 vecchi a 10 nuovi (enum SegnalazioneStato)
 * - Aggiunge id_segnalazione_madre (per DUPLICATA) e id_segnalazione_correlata
 * - Aggiunge colonna `codice` a db_azioni per identificare azioni speciali
 * - Rende nullable id_stato_segnalazione in db_azioni (riprendi/collega non hanno stato fisso)
 * - Ricrea db_stato_segnalazioni e db_azioni con il nuovo schema pulito
 */
return new class extends Migration
{
    // Mapping: vecchio ID stato → nuovo ID stato
    private const MAPPA_STATI = [
        1  => 1,   // IN ATTESA DI ESAME → NUOVA
        2  => 2,   // IN CARICO → IN_CARICO
        3  => 7,   // COMPLETATA → COMPLETATA
        4  => 9,   // ANNULLATA → ANNULLATA
        5  => 10,  // ARCHIVIATA → ARCHIVIATA
        6  => 6,   // FATTIBILITA' → SOSPESA
        7  => 4,   // ASSEGNATA IMPRESA → ASSEGNATA_IMPRESA
        8  => 3,   // SQUADRA TECNICA → ASSEGNATA_OPERATORE
        9  => 5,   // PREVENTIVO → PREVENTIVO_IN_ATTESA
        10 => 4,   // ATTESA COLLAUDO → ASSEGNATA_IMPRESA
        11 => 4,   // PREVENTIVO ACCETTATO → ASSEGNATA_IMPRESA
        12 => 6,   // SOSPESA → SOSPESA
        13 => 6,   // PARERI → SOSPESA
        14 => 6,   // SOPRALLUOGO → SOSPESA
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        // ── 1. Remap stati in segnalazioni ────────────────────────────────────
        $this->remapColonna('segnalazioni', 'id_stato_segnalazione');

        // ── 2. Remap stati in storico (stati_segnalazioni) ───────────────────
        $this->remapColonna('stati_segnalazioni', 'id_stato_segnalazione');

        // ── 3. Nuove colonne su segnalazioni ─────────────────────────────────
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->unsignedBigInteger('id_segnalazione_madre')
                  ->nullable()->after('id_stato_segnalazione');
            $table->unsignedBigInteger('id_segnalazione_correlata')
                  ->nullable()->after('id_segnalazione_madre');

            $table->foreign('id_segnalazione_madre')
                  ->references('id_segnalazione')->on('segnalazioni')->nullOnDelete();
            $table->foreign('id_segnalazione_correlata')
                  ->references('id_segnalazione')->on('segnalazioni')->nullOnDelete();
        });

        // ── 4. Aggiorna struttura db_azioni ───────────────────────────────────
        Schema::table('db_azioni', function (Blueprint $table) {
            $table->string('codice', 40)->nullable()->after('descrizione');
            $table->unsignedBigInteger('id_stato_segnalazione')->nullable()->change();
        });

        // ── 5. Ricrea db_stato_segnalazioni con nuovi 10 stati ───────────────
        DB::table('db_stato_segnalazioni')->truncate();
        DB::table('db_stato_segnalazioni')->insert([
            ['id_stato' => 1,  'descrizione' => 'Nuova',                  'iniziale' => 1, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'primary'],
            ['id_stato' => 2,  'descrizione' => 'In carico',               'iniziale' => 0, 'in_carico' => 1, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'info'],
            ['id_stato' => 3,  'descrizione' => 'Assegnata a operatore',   'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'success'],
            ['id_stato' => 4,  'descrizione' => 'Assegnata a impresa',     'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'primary'],
            ['id_stato' => 5,  'descrizione' => 'Preventivo in attesa',    'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'warning'],
            ['id_stato' => 6,  'descrizione' => 'Sospesa',                 'iniziale' => 0, 'in_carico' => 1, 'id_gestione' => 0, 'sospesa' => 1, 'chiusura' => 0, 'colore_sfondo' => 'secondary'],
            ['id_stato' => 7,  'descrizione' => 'Completata',              'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 1, 'colore_sfondo' => 'success'],
            ['id_stato' => 8,  'descrizione' => 'Duplicata',               'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 1, 'colore_sfondo' => 'warning'],
            ['id_stato' => 9,  'descrizione' => 'Annullata',               'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 1, 'colore_sfondo' => 'danger'],
            ['id_stato' => 10, 'descrizione' => 'Archiviata',              'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 1, 'colore_sfondo' => 'secondary'],
        ]);

        // ── 6. Ricrea db_azioni con nuovo schema pulito ───────────────────────
        DB::table('db_azioni')->truncate();
        DB::table('db_azioni')->insert([
            // stati_sorgente = stati da cui l'azione è applicabile
            ['id_azione' => 1,  'codice' => 'prendi_in_carico',    'descrizione' => 'Prendi in carico',       'id_stato_segnalazione' => 2,    'competenza_azione' => 0, 'colore' => 'primary',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 10, 'parametri_filtro' => '{"stati":[1]}'],
            ['id_azione' => 2,  'codice' => 'assegna_operatore',    'descrizione' => 'Assegna operatore',      'id_stato_segnalazione' => 3,    'competenza_azione' => 0, 'colore' => 'primary',   'flag_appalto' => 0, 'flag_operatore' => 1, 'flag_notifica' => 1, 'ordine' => 20, 'parametri_filtro' => '{"stati":[2,6]}'],
            ['id_azione' => 3,  'codice' => 'assegna_impresa',      'descrizione' => 'Assegna impresa',        'id_stato_segnalazione' => 4,    'competenza_azione' => 0, 'colore' => 'secondary', 'flag_appalto' => 1, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 30, 'parametri_filtro' => '{"stati":[2,6]}'],
            ['id_azione' => 4,  'codice' => 'sospendi',             'descrizione' => 'Sospendi',               'id_stato_segnalazione' => 6,    'competenza_azione' => 2, 'colore' => 'warning',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 40, 'parametri_filtro' => '{"stati":[1,2,3,4,5]}'],
            ['id_azione' => 5,  'codice' => 'riprendi',             'descrizione' => 'Riprendi',               'id_stato_segnalazione' => null, 'competenza_azione' => 2, 'colore' => 'success',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 50, 'parametri_filtro' => '{"stati":[6]}'],
            ['id_azione' => 6,  'codice' => 'presenta_preventivo',  'descrizione' => 'Presenta preventivo',    'id_stato_segnalazione' => 5,    'competenza_azione' => 1, 'colore' => 'primary',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 60, 'parametri_filtro' => '{"stati":[4]}'],
            ['id_azione' => 7,  'codice' => 'accetta_preventivo',   'descrizione' => 'Accetta preventivo',     'id_stato_segnalazione' => 4,    'competenza_azione' => 0, 'colore' => 'success',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 70, 'parametri_filtro' => '{"stati":[5]}'],
            ['id_azione' => 8,  'codice' => 'rifiuta_preventivo',   'descrizione' => 'Rifiuta preventivo',     'id_stato_segnalazione' => 4,    'competenza_azione' => 0, 'colore' => 'danger',    'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 75, 'parametri_filtro' => '{"stati":[5]}'],
            ['id_azione' => 9,  'codice' => 'chiudi',               'descrizione' => 'Chiudi',                 'id_stato_segnalazione' => 7,    'competenza_azione' => 0, 'colore' => 'success',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 80, 'parametri_filtro' => '{"stati":[2,3,4,5]}'],
            ['id_azione' => 10, 'codice' => 'segna_duplicata',      'descrizione' => 'Segna come duplicata',   'id_stato_segnalazione' => 8,    'competenza_azione' => 0, 'colore' => 'warning',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 90, 'parametri_filtro' => '{"stati":[1,2,3,4,5,6]}'],
            ['id_azione' => 11, 'codice' => 'collega',              'descrizione' => 'Collega a precedente',   'id_stato_segnalazione' => null, 'competenza_azione' => 0, 'colore' => 'secondary', 'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 95, 'parametri_filtro' => '{"stati":[1,2,3,4,5,6]}'],
            ['id_azione' => 12, 'codice' => 'annulla',              'descrizione' => 'Annulla',                'id_stato_segnalazione' => 9,    'competenza_azione' => 0, 'colore' => 'danger',    'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 100, 'parametri_filtro' => '{"stati":[1,2,3,4,5,6]}'],
            ['id_azione' => 13, 'codice' => 'archivia',             'descrizione' => 'Archivia',               'id_stato_segnalazione' => 10,   'competenza_azione' => 0, 'colore' => 'secondary', 'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 110, 'parametri_filtro' => '{"stati":[1,2,3,4,5,6]}'],
            ['id_azione' => 14, 'codice' => 'riapri',               'descrizione' => 'Riapri',                 'id_stato_segnalazione' => 2,    'competenza_azione' => 0, 'colore' => 'warning',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 120, 'parametri_filtro' => '{"stati":[7,8,9,10]}'],
        ]);

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function down(): void
    {
        // Down non supportato: il remap degli ID dati legacy non è reversibile senza backup
        throw new \RuntimeException('Migration non reversibile. Ripristina da backup.');
    }

    private function remapColonna(string $tabella, string $colonna): void
    {
        $cases = collect(self::MAPPA_STATI)
            ->map(fn ($nuovo, $vecchio) => "WHEN {$vecchio} THEN {$nuovo}")
            ->implode(' ');

        DB::statement("UPDATE `{$tabella}` SET `{$colonna}` = CASE `{$colonna}` {$cases} ELSE `{$colonna}` END");
    }
};
