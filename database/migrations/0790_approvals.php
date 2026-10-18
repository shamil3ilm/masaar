<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maker-checker for admin actions (Approvals): which actions wait for
 * approval and by whom, the requests waiting, and each approver's decision.
 *
 * A policy row only exists once someone changes the shipped default for its
 * action. A request keeps its own copy of the rules it was made under -
 * approvals needed, who may approve, when it lapses - so changing a policy
 * does not move a request already waiting.
 *
 * Policies are removable entities (soft-deleted, with who changed them).
 * Requests are evidence: never deleted, with each lifecycle actor - who
 * asked, who approved last, who rejected, who withdrew. Decisions are
 * append-only: created_at, no updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('action', 60)->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('approvals_required')->default(1);
            $table->text('approver_roles');
            $table->unsignedSmallInteger('expires_after_hours')->default(72);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('action', 60);
            $table->longText('payload');
            $table->string('summary', 500);
            $table->longText('changes');
            $table->string('target_type', 60)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->uuid('target_user_id')->nullable();
            $table->text('reason');
            $table->uuid('requested_by');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('approvals_required');
            $table->text('approver_roles');
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->uuid('withdrawn_by')->nullable();
            $table->text('decision_note')->nullable();
            $table->text('outcome')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('requested_by');
            $table->foreign('requested_by')->references('id')->on('users');
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->uuid('user_id');
            $table->string('decision', 10);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['request_id', 'user_id']);
            $table->foreign('request_id')->references('id')->on('approval_requests')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_policies');
    }
};
