<?php

declare(strict_types=1);

use App\Domains\Billing\Services\FreePeriod;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a company's free period ends, fixed when it is approved
 * (OrganizationReview). It was counted from the approval with the setting
 * of the day, so changing Finance › Settings' free months moved every free
 * period already running. Null: no free period.
 *
 * Companies approved already keep the end they have today: the current
 * setting, theirs or the platform's, from their approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('free_until')->nullable();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('free_until');
        });
    }

    /** The end each approved company has today, kept. */
    public function backfill(): void
    {
        $periods = app(FreePeriod::class);

        DB::table('organizations')
            ->whereNotNull('reviewed_at')
            ->whereNull('free_until')
            ->whereNotIn('status', [Organization::STATUS_PENDING_REVIEW, Organization::STATUS_REJECTED])
            ->select(['id', 'reviewed_at'])
            // By id: what it fills in leaves the query, so pages must not shift.
            ->chunkById(500, function ($organizations) use ($periods): void {
                foreach ($organizations as $organization) {
                    $ends = $periods->endsFrom(CarbonImmutable::parse($organization->reviewed_at), (string) $organization->id);

                    if ($ends !== null) {
                        DB::table('organizations')->where('id', $organization->id)->update(['free_until' => $ends->toDateTimeString()]);
                    }
                }
            });
    }
};
