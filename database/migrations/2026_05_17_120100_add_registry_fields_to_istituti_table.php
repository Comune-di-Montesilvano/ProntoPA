<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('istituti', function (Blueprint $table) {
            $table->string('tipo_ente', 30)->default('scuola')->after('tipo');
            $table->string('partita_iva', 11)->nullable()->after('codice_meccanografico');
            $table->string('codice_fiscale', 16)->nullable()->after('partita_iva');
            $table->string('domini_email_istituzionali', 255)->nullable()->after('email');
            $table->string('fonte_dati', 30)->default('manuale')->after('domini_email_istituzionali');
            $table->boolean('attivo')->default(true)->after('fonte_dati');

            $table->index('tipo_ente', 'istituti_tipo_ente_idx');
            $table->index('partita_iva', 'istituti_partita_iva_idx');
        });
    }

    public function down(): void
    {
        Schema::table('istituti', function (Blueprint $table) {
            $table->dropIndex('istituti_tipo_ente_idx');
            $table->dropIndex('istituti_partita_iva_idx');

            $table->dropColumn([
                'tipo_ente',
                'partita_iva',
                'codice_fiscale',
                'domini_email_istituzionali',
                'fonte_dati',
                'attivo',
            ]);
        });
    }
};
