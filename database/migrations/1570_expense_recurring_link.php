<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which recurring cost drafted an expense, and when its bill was first
 * reported missing - a draft still not recorded some days after it was
 * expected - so the daily run reports each missing bill once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->uuid('recurring_expense_id')->nullable();
            $table->timestamp('missing_reported_at')->nullable();

            $table->index('recurring_expense_id');
            $table->foreign('recurring_expense_id')->references('id')->on('recurring_expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['recurring_expense_id']);
            $table->dropIndex(['recurring_expense_id']);
            $table->dropColumn(['recurring_expense_id', 'missing_reported_at']);
        });
    }
};
