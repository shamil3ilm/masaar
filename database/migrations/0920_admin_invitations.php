<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invitation to become a platform admin, sent to an address that need
 * not have an account yet (AdminInvitations). The link proves the address;
 * accepting it makes the account if there is none, gives it the role, and
 * sends the person to enrol a second factor before the console opens.
 *
 * Only the token's hash is kept, as for organization invitations. Removable:
 * a withdrawn or replaced invitation is soft-deleted with who did it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('role', 20);
            $table->string('token_hash', 64)->unique();
            $table->uuid('invited_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->uuid('accepted_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invitations');
    }
};
