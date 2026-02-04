<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Calendars
        Schema::create('calendars', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // Personal calendar
            $table->string('name');
            $table->string('color', 7)->default('#3B82F6');
            $table->text('description')->nullable();
            $table->string('type', 20)->default('personal'); // personal, team, organization, resource
            $table->boolean('is_default')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->string('timezone', 50)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id']);
        });

        // Calendar events
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('event_type', 30)->default('event'); // event, meeting, task, reminder, holiday
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            $table->boolean('is_all_day')->default(false);
            $table->string('timezone', 50)->nullable();
            $table->string('status', 20)->default('confirmed'); // tentative, confirmed, cancelled
            $table->string('visibility', 20)->default('default'); // default, public, private
            $table->string('color', 7)->nullable();
            $table->morphs('related'); // Link to: customer, lead, invoice, employee, etc.
            $table->boolean('is_recurring')->default(false);
            $table->foreignId('recurring_event_id')->nullable(); // Parent recurring event
            $table->timestamps();
            $table->softDeletes();

            $table->index(['calendar_id', 'start_at', 'end_at']);
            $table->index(['organization_id', 'start_at']);
            $table->index(['related_type', 'related_id']);
        });

        // Recurring event rules
        Schema::create('calendar_recurring_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->string('frequency', 20); // daily, weekly, monthly, yearly
            $table->unsignedTinyInteger('interval')->default(1);
            $table->json('by_day')->nullable(); // [MO, TU, WE] for weekly
            $table->unsignedTinyInteger('by_month_day')->nullable(); // 1-31 for monthly
            $table->unsignedTinyInteger('by_month')->nullable(); // 1-12 for yearly
            $table->date('until_date')->nullable();
            $table->unsignedInteger('count')->nullable(); // Max occurrences
            $table->json('exceptions')->nullable(); // Dates to skip
            $table->timestamps();
        });

        // Event attendees
        Schema::create('calendar_event_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('role', 20)->default('attendee'); // organizer, attendee, optional
            $table->string('status', 20)->default('pending'); // pending, accepted, declined, tentative
            $table->text('comment')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        // Event reminders
        Schema::create('calendar_event_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->string('method', 20)->default('notification'); // notification, email, sms
            $table->unsignedInteger('minutes_before');
            $table->boolean('is_sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        // Tasks (integrated with calendar)
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 20)->default('medium'); // low, medium, high, urgent
            $table->string('status', 20)->default('pending'); // pending, in_progress, completed, cancelled
            $table->date('due_date')->nullable();
            $table->time('due_time')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->morphs('taskable'); // Link to: customer, lead, opportunity, etc.
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->unsignedTinyInteger('progress')->default(0); // 0-100
            $table->json('tags')->nullable();
            $table->json('checklist')->nullable(); // Sub-items
            $table->boolean('is_recurring')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'assigned_to', 'status']);
            $table->index(['organization_id', 'due_date']);
            $table->index(['taskable_type', 'taskable_id']);
        });

        // Task comments
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });

        // Reminders (standalone)
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('remind_at');
            $table->string('frequency', 20)->nullable(); // once, daily, weekly, monthly
            $table->morphs('remindable'); // Link to any entity
            $table->boolean('is_sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->boolean('is_dismissed')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'remind_at', 'is_sent']);
            $table->index(['remindable_type', 'remindable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('calendar_event_reminders');
        Schema::dropIfExists('calendar_event_attendees');
        Schema::dropIfExists('calendar_recurring_rules');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('calendars');
    }
};
