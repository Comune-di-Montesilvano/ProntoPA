<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.2 fase 2 — utenti delle scuole via SPID/CIE. Chiave d'identità =
 * codice fiscale (mai il sub OIDC: la stessa persona ne ha di diversi tra
 * SPID e CIE). bloccato_at: blocco su segnalazione della segreteria o admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('codice_fiscale', 16)->nullable()->unique();
            $table->string('oidc_subject', 255)->nullable();
            $table->timestamp('bloccato_at')->nullable();
            $table->string('motivo_blocco', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['codice_fiscale']);
            $table->dropColumn(['codice_fiscale', 'oidc_subject', 'bloccato_at', 'motivo_blocco']);
        });
    }
};
