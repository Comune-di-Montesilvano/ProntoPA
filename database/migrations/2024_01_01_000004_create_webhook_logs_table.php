<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log chiamate API in ingresso (dal sito Comune)
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('direction')->default('inbound');  // inbound | outbound
            $table->string('endpoint')->nullable();
            $table->integer('http_status')->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->unsignedBigInteger('segnalazione_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
