<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.2 fase 1 — identità per canale di accesso.
 *
 * auth_source: locale (ditte, account legacy) · ldap (dipendenti AD) · spid
 * (scuole, fase 2). email perde l'unique DB: gli account legacy disattivati
 * hanno spesso la stessa email che la persona userà via AD/SPID; le chiavi
 * d'identità vere sono ldap_guid (qui) e codice_fiscale (fase 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_source', 10)->default('locale');
            $table->string('ldap_guid', 36)->nullable()->unique();
            $table->string('two_factor_metodo', 5)->nullable();
            $table->index('auth_source', 'users_auth_source_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->index('email', 'users_email_idx');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_email_idx');
            $table->unique('email');
            $table->string('password')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_auth_source_idx');
            $table->dropUnique(['ldap_guid']);
            $table->dropColumn(['auth_source', 'ldap_guid', 'two_factor_metodo']);
        });
    }
};
