<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Organization settings
        Schema::create('organization_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('group', 50); // general, invoice, inventory, hr, etc.
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string, integer, boolean, json, date
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false); // Can be exposed to frontend
            $table->timestamps();

            $table->unique(['organization_id', 'group', 'key']);
            $table->index(['organization_id', 'group']);
        });

        // User preferences
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });

        // Number sequences
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 50); // invoice, quotation, po, work_order, etc.
            $table->string('prefix', 20)->nullable();
            $table->string('suffix', 20)->nullable();
            $table->unsignedInteger('current_number')->default(0);
            $table->unsignedSmallInteger('padding')->default(5); // Zero padding
            $table->boolean('include_year')->default(true);
            $table->boolean('include_month')->default(false);
            $table->boolean('reset_yearly')->default(true);
            $table->boolean('reset_monthly')->default(false);
            $table->unsignedSmallInteger('last_reset_year')->nullable();
            $table->unsignedSmallInteger('last_reset_month')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'branch_id', 'type']);
        });

        // Feature flags
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('feature', 100);
            $table->boolean('is_enabled')->default(false);
            $table->json('config')->nullable(); // Additional feature configuration
            $table->datetime('enabled_at')->nullable();
            $table->datetime('disabled_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('organization_settings');
    }
};
