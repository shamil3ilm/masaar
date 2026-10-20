<?php

declare(strict_types=1);

use App\Domains\Billing\Services\AnchorDayBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day of the month a recurring cost's bills fall on (1 to 31). Each
 * month's date is that day, or the month's last day when it is shorter: a
 * cost due on the 31st falls on 28 or 29 February, then on 31 March again.
 * Moving the next date on by a month from the last one instead let a short
 * month pull every later date back for good.
 *
 * Existing costs take it from where their schedule started: the first bill
 * they drafted, or their next date when they have drafted none
 * (AnchorDayBackfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_expenses', function (Blueprint $table) {
            $table->unsignedTinyInteger('anchor_day')->nullable();
        });

        (new AnchorDayBackfill)();
    }

    public function down(): void
    {
        Schema::table('recurring_expenses', function (Blueprint $table) {
            $table->dropColumn('anchor_day');
        });
    }
};
