<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sla_configurazioni', function (Blueprint $table) {
            // ore_target = valore della regola, in ore o giorni lavorativi secondo unita.
            $table->string('unita', 10)->default('ore')->after('ore_target');
        });
    }

    public function down(): void
    {
        Schema::table('sla_configurazioni', function (Blueprint $table) {
            $table->dropColumn('unita');
        });
    }
};
