<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('profile_type', 30); // invoice, bill, journal_entry, expense

            // Source document reference (the template)
            $table->string('source_type', 100); // Invoice, Bill, JournalEntry
            $table->unsignedBigInteger('source_id');

            // Scheduling
            $table->string('frequency', 20); // daily, weekly, monthly, quarterly, yearly, custom
            $table->unsignedSmallInteger('interval')->default(1); // every X frequency
            $table->json('schedule_config')->nullable(); // Day of week, day of month, etc.

            // Dates
            $table->date('start_date');
            $table->date('end_date')->nullable(); // null = no end
            $table->date('next_run_date')->nullable();
            $table->date('last_run_date')->nullable();

            // Limits
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->unsignedInteger('occurrences_count')->default(0);

            // Options
            $table->boolean('auto_send')->default(false); // Auto-send/post after creation
            $table->boolean('send_reminder')->default(false);
            $table->unsignedSmallInteger('reminder_days_before')->default(3);
            $table->string('status', 20)->default('active'); // active, paused, completed, expired

            // Notification
            $table->boolean('notify_on_creation')->default(true);
            $table->string('notify_email', 255)->nullable();

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'next_run_date']);
        });

        Schema::create('recurring_profile_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_profile_id')->constrained()->cascadeOnDelete();
            $table->string('created_type', 100); // The type of document created
            $table->unsignedBigInteger('created_id'); // The ID of created document
            $table->date('scheduled_date');
            $table->date('created_date');
            $table->string('status', 20)->default('success'); // success, failed, skipped
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['recurring_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_profile_logs');
        Schema::dropIfExists('recurring_profiles');
    }
};
