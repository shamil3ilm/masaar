<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add UUID after id
            $table->uuid('uuid')->unique()->after('id');

            // Organization relationship (nullable for super admins)
            $table->foreignId('organization_id')->nullable()->after('uuid')->constrained()->nullOnDelete();

            // Employee link (for HR module)
            $table->foreignId('employee_id')->nullable()->after('organization_id');

            // Additional contact info
            $table->string('phone', 20)->nullable()->after('email');

            // Preferences
            $table->string('preferred_language', 5)->default('en')->after('password');
            $table->string('timezone', 50)->default('Asia/Riyadh')->after('preferred_language');

            // Two-factor authentication
            $table->boolean('two_factor_enabled')->default(false)->after('timezone');
            $table->string('two_factor_secret')->nullable()->after('two_factor_enabled');

            // Status flags
            $table->boolean('is_active')->default(true)->after('two_factor_secret');
            $table->boolean('is_super_admin')->default(false)->after('is_active');

            // Tracking
            $table->timestamp('last_login_at')->nullable()->after('is_super_admin');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');

            // Soft delete
            $table->softDeletes()->after('updated_at');

            // Indexes
            $table->index('organization_id');
            $table->index('is_active');
            $table->index('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'uuid',
                'organization_id',
                'employee_id',
                'phone',
                'preferred_language',
                'timezone',
                'two_factor_enabled',
                'two_factor_secret',
                'is_active',
                'is_super_admin',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
