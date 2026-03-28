<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segnalazioni', function (Blueprint $table) {
            $table->id('id_segnalazione');
            $table->timestamp('data_segnalazione')->useCurrent();
            $table->timestamp('data_chiusura')->nullable();
            $table->unsignedBigInteger('id_tipologia_segnalazione')->default(0);
            $table->unsignedBigInteger('id_plesso')->default(0);
            $table->unsignedBigInteger('id_utente_segnalazione')->default(0);  // chi ha segnalato
            $table->unsignedBigInteger('id_cittadino_segnalazione')->default(0); // portale cittadino
            // Patrimonio (territorio)
            $table->unsignedBigInteger('id_stradario')->default(0);
            $table->unsignedBigInteger('id_area')->default(0);
            $table->unsignedBigInteger('id_immobile')->default(0);
            // Geolocalizzazione
            $table->decimal('latitudine', 10, 7)->default(0);
            $table->decimal('longitudine', 10, 7)->default(0);
            $table->integer('zoom')->default(18);
            // Contenuto
            $table->text('testo_segnalazione');
            $table->boolean('flag_riservata')->default(true);
            $table->boolean('flag_pubblicata')->default(false);
            $table->boolean('flag_evidenza')->default(false);
            // Stato e assegnazione
            $table->unsignedBigInteger('id_stato_segnalazione')->default(1);
            $table->unsignedBigInteger('id_provenienza')->default(1);
            $table->unsignedBigInteger('id_appalto')->nullable();
            $table->unsignedBigInteger('id_operatore_assegnato')->default(0);
            // Dati segnalante (libero, es. cittadino)
            $table->string('segnalante', 100)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('telefono', 50)->nullable();
            // Importi
            $table->decimal('importo_preventivo', 10, 2)->default(0);
            $table->decimal('importo_liquidato', 10, 2)->default(0);
            // Fonte esterna (integrazione sito Comune)
            $table->string('external_id')->nullable()->index(); // ID dal sito cittadino

            $table->foreign('id_tipologia_segnalazione')
                  ->references('id_tipologia_segnalazione')
                  ->on('tipologie_segnalazioni')
                  ->restrictOnDelete();
            $table->foreign('id_stato_segnalazione')
                  ->references('id_stato')
                  ->on('db_stato_segnalazioni')
                  ->restrictOnDelete();
            $table->foreign('id_provenienza')
                  ->references('id_provenienza')
                  ->on('provenienze_segnalazioni')
                  ->restrictOnDelete();
            $table->foreign('id_appalto')
                  ->references('id_appalto')
                  ->on('appalti')
                  ->nullOnDelete();
        });

        Schema::create('note_segnalazioni', function (Blueprint $table) {
            $table->id('id_nota');
            $table->unsignedBigInteger('id_segnalazione');
            $table->timestamp('data')->useCurrent();
            $table->text('testo');
            $table->unsignedBigInteger('id_utente');
            $table->boolean('visibile_web')->default(false);       // visibile a cittadini/scuole
            $table->boolean('visibile_impresa')->default(false);   // visibile all'impresa assegnata

            $table->foreign('id_segnalazione')
                  ->references('id_segnalazione')
                  ->on('segnalazioni')
                  ->cascadeOnDelete();
        });

        Schema::create('stati_segnalazioni', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_segnalazione');
            $table->timestamp('data_registrazione')->useCurrent();
            $table->unsignedBigInteger('id_stato_segnalazione');
            $table->unsignedBigInteger('id_utente');               // chi ha effettuato l'azione
            $table->bigInteger('id_utente_collegato')->default(0); // operatore/impresa collegata
            $table->bigInteger('id_appalto')->default(0);

            $table->foreign('id_segnalazione')
                  ->references('id_segnalazione')
                  ->on('segnalazioni')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stati_segnalazioni');
        Schema::dropIfExists('note_segnalazioni');
        Schema::dropIfExists('segnalazioni');
    }
};
