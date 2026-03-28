<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelle di riferimento (lookup tables):
 * provenienze_segnalazioni, istituti, profili, gruppi_segnalazioni,
 * tipologie_segnalazioni, db_stato_segnalazioni, db_azioni, db_specializzazioni, parametri
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provenienze_segnalazioni', function (Blueprint $table) {
            $table->id('id_provenienza');
            $table->string('descrizione', 50);
        });

        Schema::create('istituti', function (Blueprint $table) {
            $table->id('id_istituto');
            $table->string('descrizione', 50);
            $table->string('codice_meccanografico', 50)->default('');
            $table->string('dirigente', 50)->nullable();
            $table->string('email', 50)->nullable();
            $table->string('recapiti', 50)->nullable();
        });

        Schema::create('profili', function (Blueprint $table) {
            $table->id('id_profilo');
            $table->string('descrizione', 50)->nullable();
            $table->boolean('limita_istituto')->default(false);
            $table->unsignedBigInteger('id_istituto')->nullable();
            // 0=NO, 1=SOLO SCUOLE, 2=SOLO CITTADINI, 3=SOLO UNA TIPOLOGIA
            $table->tinyInteger('limita_segnalazioni')->nullable();
            $table->unsignedBigInteger('id_tipologia_segnalazione')->nullable();

            $table->foreign('id_istituto')->references('id_istituto')->on('istituti')->nullOnDelete();
        });

        Schema::create('plessi', function (Blueprint $table) {
            $table->id('id_plesso');
            $table->unsignedBigInteger('id_istituto');
            $table->string('nome', 50)->nullable();
            $table->string('codice_meccanografico', 50)->nullable();
            $table->string('indirizzo', 50)->nullable();
            $table->string('referente', 50)->nullable();
            $table->string('email', 50)->nullable();
            $table->string('recapiti', 50)->nullable();

            $table->foreign('id_istituto')->references('id_istituto')->on('istituti')->cascadeOnDelete();
        });

        Schema::create('gruppi_segnalazioni', function (Blueprint $table) {
            $table->id('id_gruppo');
            $table->string('descrizione', 50);
            $table->string('icona', 50)->default('');
            // 0=Scuole, 1=Immobili, 2=Parchi, 3=Territorio
            $table->tinyInteger('tipologia')->default(0);
            $table->boolean('cittadini')->default(false);
        });

        Schema::create('tipologie_segnalazioni', function (Blueprint $table) {
            $table->id('id_tipologia_segnalazione');
            $table->string('descrizione', 50);
            $table->string('icona', 50)->default('');
            $table->unsignedBigInteger('id_gruppo');

            $table->foreign('id_gruppo')->references('id_gruppo')->on('gruppi_segnalazioni')->cascadeOnDelete();
        });

        // Aggiunge FK tipologie a profili (ora che tipologie esiste)
        Schema::table('profili', function (Blueprint $table) {
            $table->foreign('id_tipologia_segnalazione')
                  ->references('id_tipologia_segnalazione')
                  ->on('tipologie_segnalazioni')
                  ->nullOnDelete();
        });

        Schema::create('db_stato_segnalazioni', function (Blueprint $table) {
            $table->id('id_stato');
            $table->string('descrizione', 50);
            $table->boolean('iniziale')->default(false);
            $table->boolean('in_carico')->default(false);
            $table->boolean('id_gestione')->default(false);   // In Gestione flag
            $table->boolean('sospesa')->default(false);
            $table->boolean('chiusura')->default(false);
            // Bootstrap color class: danger, success, primary, secondary, warning, none
            $table->string('colore_sfondo', 20)->default('none');
        });

        Schema::create('db_azioni', function (Blueprint $table) {
            $table->id('id_azione');
            $table->string('descrizione', 50);
            $table->unsignedBigInteger('id_stato_segnalazione'); // stato risultante
            // 0=Ente, 1=Impresa, 2=Entrambi
            $table->tinyInteger('competenza_azione')->default(0);
            $table->string('colore', 20)->default('primary');
            $table->boolean('flag_appalto')->default(false);
            $table->boolean('flag_operatore')->default(false);
            $table->boolean('flag_notifica')->default(false);
            $table->integer('ordine')->default(0);
            $table->json('parametri_filtro')->nullable();       // JSON filtri visibilità

            $table->foreign('id_stato_segnalazione')
                  ->references('id_stato')
                  ->on('db_stato_segnalazioni')
                  ->cascadeOnDelete();
        });

        Schema::create('db_specializzazioni', function (Blueprint $table) {
            $table->id('id_specializzazione');
            $table->string('descrizione', 50);
        });

        Schema::create('parametri', function (Blueprint $table) {
            $table->id('id_parametro');
            $table->string('descrizione', 50)->nullable();
            $table->string('valore', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('profili', function (Blueprint $table) {
            $table->dropForeign(['id_tipologia_segnalazione']);
        });
        Schema::dropIfExists('parametri');
        Schema::dropIfExists('db_specializzazioni');
        Schema::dropIfExists('db_azioni');
        Schema::dropIfExists('db_stato_segnalazioni');
        Schema::dropIfExists('tipologie_segnalazioni');
        Schema::dropIfExists('gruppi_segnalazioni');
        Schema::dropIfExists('plessi');
        Schema::dropIfExists('profili');
        Schema::dropIfExists('istituti');
        Schema::dropIfExists('provenienze_segnalazioni');
    }
};
