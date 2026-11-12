<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('impostazioni')->where('chiave', 'telegram_notifica_stato_critico')->delete();
    }

    public function down(): void
    {
        // Chiave mai letta da nessun codice: non si ripristina.
    }
};
