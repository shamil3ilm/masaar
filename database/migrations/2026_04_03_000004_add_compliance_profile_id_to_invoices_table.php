<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Nullable: existing invoices don't have a profile yet (backfilled separately)
            $table->foreignUuid('compliance_profile_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('compliance_profiles')
                ->nullOnDelete();

            $table->index('compliance_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['compliance_profile_id']);
            $table->dropIndex(['compliance_profile_id']);
            $table->dropColumn('compliance_profile_id');
        });
    }
};
