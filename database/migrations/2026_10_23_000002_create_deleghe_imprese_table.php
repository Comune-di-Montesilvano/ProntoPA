<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleghe persona fisica (codice fiscale SPID/CIE) → ditta, sempre create
 * da un operatore del comune. Separate da `deleghe` (scuole): niente
 * richiesta, segreteria né rinnovo annuale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imprese', function (Blueprint $table) {
            $table->boolean('attiva')->default(true)->after('note');
        });

        Schema::create('deleghe_imprese', function (Blueprint $table) {
            $table->id();
            $table->char('codice_fiscale', 16);
            // NULL finché la persona non entra con SPID la prima volta
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('id_impresa');
            $table->string('stato', 12); // attiva · revocata
            $table->string('motivo', 255)->nullable();
            $table->foreignId('creata_da')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revocata_da')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revocata_at')->nullable();
            $table->timestamps();

            $table->foreign('id_impresa')->references('id_impresa')->on('imprese');
            $table->index(['codice_fiscale', 'stato']);
            $table->index(['user_id', 'stato']);
            $table->index(['id_impresa', 'stato']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleghe_imprese');
        Schema::table('imprese', function (Blueprint $table) {
            $table->dropColumn('attiva');
        });
    }
};
