<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Approval workflow definitions
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('entity_type', 50); // invoice, purchase_order, expense, leave_request, etc.
            $table->text('description')->nullable();
            $table->json('conditions')->nullable(); // When to apply this workflow (amount > X, department = Y)
            $table->unsignedTinyInteger('priority')->default(0); // Higher priority workflows checked first
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'entity_type', 'is_active']);
        });

        // Workflow steps (approval chain)
        Schema::create('approval_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('approval_workflows')->cascadeOnDelete();
            $table->unsignedTinyInteger('step_order');
            $table->string('name');
            $table->string('approver_type', 30); // user, role, department_head, reporting_manager, custom
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('approval_type', 20)->default('single'); // single, all, any (for multiple approvers)
            $table->unsignedInteger('timeout_hours')->nullable(); // Auto-escalate after X hours
            $table->foreignId('escalate_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('can_reject')->default(true);
            $table->boolean('can_delegate')->default(false);
            $table->boolean('requires_comment')->default(false);
            $table->timestamps();

            $table->unique(['workflow_id', 'step_order']);
        });

        // Approval requests
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('approval_workflows')->cascadeOnDelete();
            $table->morphs('approvable'); // Polymorphic: invoice, expense, leave_request, etc.
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, cancelled
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->decimal('amount', 15, 2)->nullable(); // For amount-based routing
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['approvable_type', 'approvable_id']);
        });

        // Individual approval actions
        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->foreignId('step_id')->constrained('approval_workflow_steps')->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 20); // approved, rejected, delegated, escalated
            $table->text('comment')->nullable();
            $table->foreignId('delegated_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->index(['request_id', 'step_id']);
        });

        // Approval delegates (temporary delegation)
        Schema::create('approval_delegates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delegator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->json('entity_types')->nullable(); // Limit to specific types
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['delegator_id', 'is_active']);
            $table->index(['delegate_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegates');
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_workflow_steps');
        Schema::dropIfExists('approval_workflows');
    }
};
