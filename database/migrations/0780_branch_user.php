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
            $table->timestamps();

            $table->unique(['branch_id', 'user_id'], 'branch_user_unique');
            $table->index(['org_id', 'user_id'], 'branch_user_org_user_idx');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
    }
};
