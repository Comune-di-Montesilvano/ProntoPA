<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('istituti', function (Blueprint $table) {
            $table->string('tipo', 50)->nullable()->default('Scuola')->after('descrizione');
        });

        DB::table('istituti')->whereNull('tipo')->update(['tipo' => 'Scuola']);
    }

    public function down(): void
    {
        Schema::table('istituti', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
