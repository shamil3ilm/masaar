<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The branch a person chose in the portal's switcher, per organization:
 * null for all of them. Kept on the membership beside the person's own
 * paper (0620), and let go of when the branch is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_user', function (Blueprint $table) {
            $table->uuid('working_branch_id')->nullable();
            $table->foreign('working_branch_id', 'organization_user_working_branch_foreign')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organization_user', function (Blueprint $table) {
            $table->dropForeign('organization_user_working_branch_foreign');
            $table->dropColumn('working_branch_id');
        });
    }
};
