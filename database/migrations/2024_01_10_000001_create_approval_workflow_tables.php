<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Approval workflow templates
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 50);
            $table->text('description')->nullable();

            // What this workflow applies to
            $table->string('approvable_type', 100); // Invoice, PurchaseOrder, LeaveRequest, etc.

            // Conditions for auto-triggering
            $table->decimal('min_amount', 15, 4)->nullable();
            $table->decimal('max_amount', 15, 4)->nullable();
            $table->json('conditions')->nullable(); // Additional conditions as JSON

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(0); // Higher = checked first
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'approvable_type', 'is_active']);
        });

        // Approval workflow steps
        Schema::create('approval_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sequence')->default(0);

            // Who can approve
            $table->enum('approver_type', [
                'user',           // Specific user
                'role',           // Any user with role
                'department_head', // Head of submitter's department
                'reporting_manager', // Submitter's reporting manager
                'custom',         // Custom logic via code
            ]);
            $table->unsignedBigInteger('approver_id')->nullable(); // User ID or Role ID
            $table->string('approver_custom', 100)->nullable(); // Custom approver identifier

            // Approval settings
            $table->boolean('requires_all')->default(false); // All must approve vs any one
            $table->unsignedSmallInteger('min_approvers')->default(1);
            $table->unsignedSmallInteger('timeout_hours')->nullable(); // Auto-escalate after
            $table->boolean('can_skip')->default(false);
            $table->boolean('can_delegate')->default(true);

            $table->timestamps();

            $table->index(['approval_workflow_id', 'sequence']);
        });

        // Approval requests (instances of workflow)
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();

            // What is being approved (polymorphic)
            $table->string('approvable_type', 100);
            $table->unsignedBigInteger('approvable_id');

            // Current state
            $table->unsignedBigInteger('current_step_id')->nullable();
            $table->enum('status', [
                'pending',
                'in_progress',
                'approved',
                'rejected',
                'cancelled',
                'expired',
            ])->default('pending');

            // Metadata
            $table->decimal('amount', 15, 4)->nullable();
            $table->text('notes')->nullable();
            $table->datetime('submitted_at')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id']);
            $table->index(['organization_id', 'status']);
        });

        // Individual approval actions
        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->constrained('approval_workflow_steps')->cascadeOnDelete();

            // Who is being asked to approve
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();

            // Status of this specific approval
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'delegated',
                'skipped',
                'expired',
            ])->default('pending');

            // Delegation
            $table->foreignId('delegated_to')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('delegated_at')->nullable();

            // Action details
            $table->text('comments')->nullable();
            $table->datetime('action_at')->nullable();
            $table->foreignId('action_by')->nullable()->constrained('users')->nullOnDelete();

            // Expiry
            $table->datetime('expires_at')->nullable();
            $table->boolean('reminder_sent')->default(false);

            $table->timestamps();

            $table->index(['approval_request_id', 'status']);
            $table->index(['assigned_to', 'status']);
        });

        // Approval delegation rules
        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delegate_to')->constrained('users')->cascadeOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason', 500)->nullable();

            // Optional: only delegate specific workflow types
            $table->string('approvable_type', 100)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_workflow_steps');
        Schema::dropIfExists('approval_workflows');
    }
};
