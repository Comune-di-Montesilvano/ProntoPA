<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('id_istituto')->nullable()->after('id_profilo');
            $table->string('approval_status', 20)->default('approved')->after('attivo');
            $table->timestamp('approved_at')->nullable()->after('approval_status');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');

            $table->timestamp('email_confermata_annualmente_at')->nullable()->after('approved_by');
            $table->timestamp('prossima_verifica_annuale_at')->nullable()->after('email_confermata_annualmente_at');
            $table->string('annual_verification_token_hash', 64)->nullable()->after('prossima_verifica_annuale_at');
            $table->timestamp('annual_verification_sent_at')->nullable()->after('annual_verification_token_hash');
            $table->timestamp('annual_verification_due_at')->nullable()->after('annual_verification_sent_at');
            $table->timestamp('annual_verification_reminder_at')->nullable()->after('annual_verification_due_at');

            $table->index(['approval_status', 'attivo'], 'users_approval_attivo_idx');
            $table->index('prossima_verifica_annuale_at', 'users_prossima_verifica_idx');

            $table->foreign('id_istituto')->references('id_istituto')->on('istituti')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['id_istituto']);
            $table->dropForeign(['approved_by']);
            $table->dropIndex('users_approval_attivo_idx');
            $table->dropIndex('users_prossima_verifica_idx');

            $table->dropColumn([
                'id_istituto',
                'approval_status',
                'approved_at',
                'approved_by',
                'email_confermata_annualmente_at',
                'prossima_verifica_annuale_at',
                'annual_verification_token_hash',
                'annual_verification_sent_at',
                'annual_verification_due_at',
                'annual_verification_reminder_at',
            ]);
        });
    }
};
