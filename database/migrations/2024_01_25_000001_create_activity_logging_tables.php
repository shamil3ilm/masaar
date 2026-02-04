<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Activity log - comprehensive tracking
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // What happened
            $table->string('action', 50); // created, updated, deleted, viewed, exported, imported, approved, rejected, etc.
            $table->string('entity_type', 100); // Invoice, Customer, Product, etc.
            $table->string('entity_id', 100)->nullable();
            $table->string('entity_name')->nullable(); // Human-readable identifier

            // Details
            $table->string('description'); // Human-readable description
            $table->json('old_values')->nullable(); // Previous state
            $table->json('new_values')->nullable(); // New state
            $table->json('changed_fields')->nullable(); // List of changed field names
            $table->json('metadata')->nullable(); // Additional context

            // Request context
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('request_method', 10)->nullable();
            $table->string('request_url')->nullable();
            $table->string('session_id', 100)->nullable();

            // Categorization
            $table->string('module', 50)->nullable(); // sales, inventory, hr, etc.
            $table->string('severity', 20)->default('info'); // info, warning, error, critical
            $table->boolean('is_system')->default(false); // System-generated vs user action

            $table->timestamp('created_at');

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'user_id', 'created_at']);
            $table->index(['organization_id', 'entity_type', 'entity_id']);
            $table->index(['organization_id', 'action']);
            $table->index(['organization_id', 'module']);
        });

        // User sessions tracking
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 100)->unique();
            $table->string('ip_address', 45);
            $table->string('user_agent')->nullable();
            $table->string('device_type', 20)->nullable(); // desktop, mobile, tablet
            $table->string('browser', 50)->nullable();
            $table->string('os', 50)->nullable();
            $table->string('location')->nullable(); // City, Country from IP
            $table->timestamp('login_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('logout_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('logout_reason', 50)->nullable(); // manual, expired, forced

            $table->index(['user_id', 'is_active']);
            $table->index(['session_id']);
        });

        // Login history
        Schema::create('login_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('ip_address', 45);
            $table->string('user_agent')->nullable();
            $table->string('status', 20); // success, failed, blocked, 2fa_required
            $table->string('failure_reason')->nullable();
            $table->timestamp('attempted_at');

            $table->index(['user_id', 'attempted_at']);
            $table->index(['ip_address', 'attempted_at']);
            $table->index(['email', 'attempted_at']);
        });

        // Entity views tracking (for "recently viewed")
        Schema::create('entity_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 100);
            $table->string('entity_id', 100);
            $table->string('entity_name')->nullable();
            $table->timestamp('viewed_at');

            $table->unique(['user_id', 'entity_type', 'entity_id']);
            $table->index(['user_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_views');
        Schema::dropIfExists('login_history');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('activity_logs');
    }
};
