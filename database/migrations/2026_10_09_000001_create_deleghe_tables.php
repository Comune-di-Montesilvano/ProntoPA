<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Deleghe scuole (0.8.0): persona → istituto/plesso, decise dalla segreteria.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deleghe', function (Blueprint $table) {
            $table->id();
            // NULL solo per pre-delega admin non ancora agganciata al primo login SPID
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('codice_fiscale', 16);
            $table->unsignedBigInteger('id_istituto');
            $table->unsignedBigInteger('id_plesso')->nullable(); // NULL = tutto l'istituto
            $table->uuid('gruppo_richiesta');
            $table->string('stato', 12); // richiesta · attiva · rifiutata · revocata · scaduta
            $table->string('email_destinatario', 255)->nullable();
            $table->timestamp('richiesta_inviata_at')->nullable(); // NULL = in coda (tetto giornaliero)
            $table->timestamp('decisa_at')->nullable();
            $table->string('decisa_via', 10)->nullable(); // segreteria · admin · sistema · utente
            $table->foreignId('decisa_da')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo', 255)->nullable();
            $table->timestamp('valida_fino_at')->nullable();
            $table->timestamp('rinnovo_inviato_at')->nullable();
            $table->timestamp('avviso_inviato_at')->nullable();
            $table->char('token_hash', 64)->nullable();
            $table->timestamp('token_scadenza_at')->nullable();
            $table->timestamps();

            $table->foreign('id_istituto')->references('id_istituto')->on('istituti');
            $table->foreign('id_plesso')->references('id_plesso')->on('plessi');
            $table->index(['user_id', 'stato']);
            $table->index(['codice_fiscale', 'stato']);
            $table->index(['id_istituto', 'stato']);
            $table->index(['stato', 'valida_fino_at']);
            $table->index('gruppo_richiesta');
            $table->index('token_hash');
        });

        Schema::create('deleghe_storico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_delega')->constrained('deleghe')->cascadeOnDelete();
            $table->string('evento', 12);
            $table->string('via', 10); // utente · segreteria · admin · sistema
            $table->foreignId('id_utente')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleghe_storico');
        Schema::dropIfExists('deleghe');
    }
};
