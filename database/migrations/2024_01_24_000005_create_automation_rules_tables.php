<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Automation rules
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('trigger_type', 50); // event, schedule, manual
            $table->string('trigger_event')->nullable(); // invoice.created, payment.received, etc.
            $table->string('trigger_schedule')->nullable(); // Cron expression for scheduled
            $table->string('entity_type', 50); // invoice, customer, expense, etc.
            $table->json('conditions'); // Array of condition groups (AND/OR)
            $table->json('actions'); // Array of actions to execute
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('stop_on_match')->default(false); // Stop processing other rules
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('execution_count')->default(0);
            $table->timestamp('last_executed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'entity_type', 'is_active']);
            $table->index(['organization_id', 'trigger_event', 'is_active']);
        });

        // Rule execution logs
        Schema::create('automation_rule_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->morphs('entity'); // The entity that triggered the rule
            $table->string('status', 20); // success, failed, skipped
            $table->json('conditions_matched')->nullable();
            $table->json('actions_executed')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('execution_time_ms')->nullable();
            $table->timestamps();

            $table->index(['rule_id', 'created_at']);
        });

        // Scheduled automation jobs
        Schema::create('automation_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->timestamp('scheduled_for');
            $table->timestamp('executed_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending, running, completed, failed
            $table->timestamps();

            $table->index(['scheduled_for', 'status']);
        });

        // Email templates for automation
        Schema::create('automation_email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('subject');
            $table->text('body_html');
            $table->text('body_text')->nullable();
            $table->json('variables')->nullable(); // Available variables
            $table->string('category', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_email_templates');
        Schema::dropIfExists('automation_schedules');
        Schema::dropIfExists('automation_rule_logs');
        Schema::dropIfExists('automation_rules');
    }
};
