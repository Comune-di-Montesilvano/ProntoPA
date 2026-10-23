<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v0.8.19: /login solo AD, ditte e scuole via SPID. Gli account locali
 * (ditte, segnalatori legacy, eventuali admin storici) non possono più
 * entrare: disattivati, mai cancellati (lo storico punta a loro).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('auth_source', 'locale')->update(['attivo' => false]);
    }

    public function down(): void
    {
        // Irreversibile di proposito: riattivare in blocco account senza login non ha senso.
    }
};
