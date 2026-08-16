<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE segnalazioni ADD FULLTEXT INDEX ft_testo_segnalazione (testo_segnalazione)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE segnalazioni DROP INDEX ft_testo_segnalazione');
        }
    }
};
