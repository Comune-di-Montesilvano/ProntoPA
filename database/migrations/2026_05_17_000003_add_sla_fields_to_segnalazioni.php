<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->timestamp('data_scadenza_sla')->nullable()->after('data_chiusura');
            $table->boolean('sla_violato')->default(false)->after('data_scadenza_sla');
            $table->boolean('sla_warning_inviato')->default(false)->after('sla_violato');
        });
    }

    public function down(): void
    {
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->dropColumn(['data_scadenza_sla', 'sla_violato', 'sla_warning_inviato']);
        });
    }
};
