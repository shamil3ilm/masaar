<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization's admin inviting someone to join it.
 *
 * Membership was created in one place - the founder, at registration - so a
 * company had exactly one user for good. The token is stored as a hash; the
 * link is the only place it exists in full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('email');
            $table->string('role', 20);
            $table->string('token_hash', 64)->unique();
            $table->uuid('invited_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'email']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
