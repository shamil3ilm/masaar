<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Task boards (projects/boards container)
        Schema::create('task_boards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('board_type', 30)->default('kanban'); // kanban, scrum, simple
            $table->string('visibility', 20)->default('private'); // private, team, organization
            $table->string('color', 7)->nullable(); // Hex color for board
            $table->string('icon')->nullable();
            $table->boolean('is_template')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'board_type']);
        });

        // Board members (who has access to the board)
        Schema::create('task_board_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member'); // owner, admin, member, viewer
            $table->timestamp('joined_at');
            $table->timestamps();

            $table->unique(['board_id', 'user_id']);
            $table->index(['user_id']);
        });

        // Board columns/lists (Kanban columns)
        Schema::create('task_board_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('wip_limit')->nullable(); // Work-in-progress limit
            $table->boolean('is_done_column')->default(false); // Marks tasks as completed
            $table->boolean('is_default')->default(false); // Default column for new tasks
            $table->timestamps();

            $table->index(['board_id', 'position']);
        });

        // Tasks (cards)
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->foreignId('column_id')->constrained('task_board_columns')->cascadeOnDelete();
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->nullOnDelete(); // Subtasks
            $table->string('task_number', 30); // BOARD-123
            $table->string('title');
            $table->text('description')->nullable();

            // Classification
            $table->string('task_type', 30)->default('task'); // task, bug, feature, story, epic
            $table->string('priority', 20)->default('medium'); // critical, high, medium, low
            $table->string('status', 30)->default('open'); // open, in_progress, review, completed, cancelled

            // Assignment
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();

            // Dates
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Estimation
            $table->unsignedInteger('estimated_hours')->nullable();
            $table->unsignedInteger('actual_hours')->nullable();
            $table->unsignedSmallInteger('story_points')->nullable(); // For agile

            // Position in column
            $table->unsignedInteger('position')->default(0);

            // Metadata
            $table->json('tags')->nullable();
            $table->string('color', 7)->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->text('blocked_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['board_id', 'column_id', 'position']);
            $table->index(['assignee_id', 'status']);
            $table->index(['organization_id', 'due_date']);
            $table->index(['board_id', 'task_type']);
        });

        // Task labels/tags
        Schema::create('task_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 7);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['board_id', 'name']);
        });

        // Task to label pivot
        Schema::create('task_label_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('task_labels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'label_id']);
        });

        // Task watchers (users following task updates)
        Schema::create('task_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
        });

        // Task comments
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('task_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->json('mentions')->nullable(); // @user mentions
            $table->boolean('is_edited')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['task_id', 'created_at']);
        });

        // Task attachments
        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type', 50);
            $table->unsignedBigInteger('file_size');
            $table->boolean('is_cover')->default(false); // Cover image for card
            $table->timestamps();

            $table->index(['task_id']);
        });

        // Task checklists
        Schema::create('task_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['task_id', 'position']);
        });

        // Checklist items
        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained('task_checklists')->cascadeOnDelete();
            $table->string('content');
            $table->boolean('is_completed')->default(false);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['checklist_id', 'position']);
        });

        // Task time tracking
        Schema::create('task_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable(); // Calculated or manual
            $table->boolean('is_billable')->default(true);
            $table->timestamps();

            $table->index(['task_id', 'user_id']);
            $table->index(['user_id', 'started_at']);
        });

        // Task dependencies
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('dependency_type', 20)->default('finish_to_start'); // finish_to_start, start_to_start, finish_to_finish, start_to_finish
            $table->timestamps();

            $table->unique(['task_id', 'depends_on_task_id']);
        });

        // Task activity/history log
        Schema::create('task_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('activity_type', 50); // created, status_changed, assigned, commented, attachment_added, etc.
            $table->string('field_name')->nullable(); // Which field changed
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });

        // Sprints (for Scrum boards)
        Schema::create('task_sprints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->string('name');
            $table->text('goal')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('planned'); // planned, active, completed
            $table->unsignedInteger('total_points')->default(0);
            $table->unsignedInteger('completed_points')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['board_id', 'status']);
        });

        // Sprint to task pivot (tasks in a sprint)
        Schema::create('task_sprint_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sprint_id')->constrained('task_sprints')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('points')->nullable();
            $table->timestamp('added_at');
            $table->timestamps();

            $table->unique(['sprint_id', 'task_id']);
        });

        // Board templates (predefined board setups)
        Schema::create('task_board_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete(); // NULL for system templates
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('board_type', 30);
            $table->json('columns'); // Default columns configuration
            $table->json('labels')->nullable(); // Default labels
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_board_templates');
        Schema::dropIfExists('task_sprint_items');
        Schema::dropIfExists('task_sprints');
        Schema::dropIfExists('task_activities');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('task_time_entries');
        Schema::dropIfExists('task_checklist_items');
        Schema::dropIfExists('task_checklists');
        Schema::dropIfExists('task_attachments');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_watchers');
        Schema::dropIfExists('task_label_assignments');
        Schema::dropIfExists('task_labels');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_board_columns');
        Schema::dropIfExists('task_board_members');
        Schema::dropIfExists('task_boards');
    }
};
