<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why, when and by whom a platform admin suspended an organization
 * (OrganizationStatus). Its status says that it is; these say why, for its
 * own people as much as for support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
            $table->uuid('suspended_by')->nullable();
            $table->string('suspension_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspended_by', 'suspension_reason']);
        });
    }
};
