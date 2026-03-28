<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impostazioni', function (Blueprint $table) {
            $table->string('chiave')->primary();
            $table->text('valore')->nullable();
            $table->string('tipo', 20)->default('text')
                  ->comment('text | boolean | color | url | image | integer');
            $table->string('gruppo', 30)->default('generale')
                  ->comment('brand | email | mappa | webhook | generale');
            $table->string('descrizione')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impostazioni');
    }
};
