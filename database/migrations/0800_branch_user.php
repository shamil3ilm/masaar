<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which members work at which branches, and as what: issuing there (member)
 * or reading (viewer).
 *
 * A member with no row here works at every branch, as everyone did before;
 * one with rows works at those alone (BranchAccess). An administrator of the
 * organization works at every branch whatever is here. One row per person
 * per branch.
 *
 * Taking someone off a branch soft-deletes the row (a removable entity), and
 * putting them back restores it, so the unique pair still allows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('branch_id');
            $table->uuid('user_id');
            $table->string('role', 20);
            // Who made, last changed and removed it (RecordsActors).
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'user_id'], 'branch_user_unique');
            $table->index(['org_id', 'user_id'], 'branch_user_org_user_idx');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            foreach (['created_by', 'updated_by', 'deleted_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
    }
};
