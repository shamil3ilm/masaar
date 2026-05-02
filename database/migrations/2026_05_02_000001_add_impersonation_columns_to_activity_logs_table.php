<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('impersonated_by_id')->nullable()->after('user_id');
            $table->char('impersonation_session_id', 36)->nullable()->after('impersonated_by_id');

            $table->foreign('impersonated_by_id')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index('impersonation_session_id');
            $table->index('impersonated_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['impersonated_by_id']);
            $table->dropIndex(['impersonation_session_id']);
            $table->dropIndex(['impersonated_by_id']);
            $table->dropColumn(['impersonation_session_id', 'impersonated_by_id']);
        });
    }
};
