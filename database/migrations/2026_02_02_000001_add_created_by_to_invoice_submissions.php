<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds created_by column to track which user submitted each invoice.
     * Required for customer portal user-level activity filtering.
     */
    public function up(): void
    {
        Schema::table('invoice_submissions', function (Blueprint $table) {
            $table->foreignUuid('created_by')
                ->nullable()
                ->after('organization_id')
                ->constrained('users')
                ->nullOnDelete();

            // Index for efficient user activity queries
            $table->index(['organization_id', 'created_by', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_submissions', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropIndex(['organization_id', 'created_by', 'created_at']);
            $table->dropColumn('created_by');
        });
    }
};
