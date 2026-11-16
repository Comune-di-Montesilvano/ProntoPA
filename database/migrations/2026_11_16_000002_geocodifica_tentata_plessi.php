<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 1.0.2: quando si è provato a trovare la posizione della sede (null = mai: ci pensa il giro delle 06:10). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plessi', function (Blueprint $table) {
            $table->dateTime('geocodifica_tentata_at')->nullable()->after('longitudine');
        });
    }

    public function down(): void
    {
        Schema::table('plessi', function (Blueprint $table) {
            $table->dropColumn('geocodifica_tentata_at');
        });
    }
};
