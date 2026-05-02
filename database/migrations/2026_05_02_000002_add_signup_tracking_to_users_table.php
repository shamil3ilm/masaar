<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('registration_source', 30)->nullable()->after('last_login_ip');
            $table->string('utm_source', 100)->nullable()->after('registration_source');
            $table->string('utm_medium', 100)->nullable()->after('utm_source');
            $table->string('utm_campaign', 150)->nullable()->after('utm_medium');
            $table->string('utm_term', 150)->nullable()->after('utm_campaign');
            $table->string('utm_content', 150)->nullable()->after('utm_term');
            $table->string('referral_code', 50)->nullable()->after('utm_content');
            $table->string('registration_device_type', 20)->nullable()->after('referral_code');
            $table->string('registration_ip', 45)->nullable()->after('registration_device_type');
            $table->unsignedBigInteger('invited_by_user_id')->nullable()->after('registration_ip');

            $table->foreign('invited_by_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index('invited_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['invited_by_user_id']);
            $table->dropIndex(['invited_by_user_id']);
            $table->dropColumn([
                'invited_by_user_id', 'registration_ip', 'registration_device_type',
                'referral_code', 'utm_content', 'utm_term', 'utm_campaign',
                'utm_medium', 'utm_source', 'registration_source',
            ]);
        });
    }
};
