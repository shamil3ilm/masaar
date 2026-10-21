<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organization a request is made in, when its own people decide it: a
 * credit or debit note one member asks for and another approves
 * (OrganizationApprovals). Null for the platform's own requests, which its
 * admins decide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->uuid('org_id')->nullable()->after('id');

            $table->index(['org_id', 'status']);
            $table->foreign('org_id')->references('id')->on('organizations');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
            $table->dropIndex(['org_id', 'status']);
            $table->dropColumn('org_id');
        });
    }
};
