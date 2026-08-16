<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('squadre', function (Blueprint $table) {
            $table->id('id_squadra');
            $table->string('nome', 100);
            $table->unsignedBigInteger('id_caposquadra');
            $table->boolean('attiva')->default(true);
            $table->timestamps();

            $table->foreign('id_caposquadra')
                ->references('id')->on('users')
                ->restrictOnDelete();
        });

        Schema::create('squadra_user', function (Blueprint $table) {
            $table->unsignedBigInteger('id_squadra');
            $table->unsignedBigInteger('user_id');

            $table->primary(['id_squadra', 'user_id']);
            $table->foreign('id_squadra')
                ->references('id_squadra')->on('squadre')
                ->cascadeOnDelete();
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });

        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->unsignedBigInteger('id_squadra_assegnata')->nullable()->after('id_operatore_assegnato');
            $table->foreign('id_squadra_assegnata')
                ->references('id_squadra')->on('squadre')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->dropForeign(['id_squadra_assegnata']);
            $table->dropColumn('id_squadra_assegnata');
        });
        Schema::dropIfExists('squadra_user');
        Schema::dropIfExists('squadre');
    }
};
