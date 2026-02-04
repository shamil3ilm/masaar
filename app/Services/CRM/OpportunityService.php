<?php

declare(strict_types=1);

namespace App\Services\CRM;

use App\Models\CRM\Activity;
use App\Models\CRM\Opportunity;
use App\Models\CRM\PipelineStage;
use App\Services\Core\NumberGeneratorService;
use Illuminate\Support\Facades\DB;

class OpportunityService
{
    public function __construct(
        private NumberGeneratorService $numberGenerator
    ) {}

    /**
     * Create a new opportunity.
     */
    public function create(array $data): Opportunity
    {
        return DB::transaction(function () use ($data) {
            if (empty($data['opportunity_number'])) {
                $data['opportunity_number'] = $this->numberGenerator->generate('OPP');
            }

            // Set initial stage if not provided
            if (empty($data['pipeline_stage_id'])) {
                $firstStage = PipelineStage::where('organization_id', auth()->user()->organization_id)
                    ->active()
                    ->ordered()
                    ->first();

                if ($firstStage) {
                    $data['pipeline_stage_id'] = $firstStage->id;
                    $data['probability'] = $firstStage->probability;
                }
            }

            $data['status'] = $data['status'] ?? Opportunity::STATUS_OPEN;

            $opportunity = Opportunity::create($data);

            return $opportunity;
        });
    }

    /**
     * Update an opportunity.
     */
    public function update(Opportunity $opportunity, array $data): Opportunity
    {
        if ($opportunity->isClosed()) {
            throw new \InvalidArgumentException('Closed opportunities cannot be updated.');
        }

        $opportunity->update($data);

        return $opportunity->fresh();
    }

    /**
     * Move opportunity to a different pipeline stage.
     */
    public function moveToStage(Opportunity $opportunity, PipelineStage $stage): Opportunity
    {
        if ($opportunity->isClosed()) {
            throw new \InvalidArgumentException('Cannot change stage of closed opportunities.');
        }

        $previousStage = $opportunity->pipelineStage;
        $opportunity->updateStage($stage);

        // Log activity
        Activity::create([
            'organization_id' => $opportunity->organization_id,
            'activity_type' => Activity::TYPE_NOTE,
            'subject' => "Stage changed from {$previousStage?->name} to {$stage->name}",
            'related_type' => Opportunity::class,
            'related_id' => $opportunity->id,
            'status' => Activity::STATUS_COMPLETED,
            'completed_at' => now(),
            'created_by' => auth()->id(),
        ]);

        return $opportunity->fresh();
    }

    /**
     * Mark opportunity as won.
     */
    public function win(Opportunity $opportunity, ?string $reason = null): Opportunity
    {
        if ($opportunity->isClosed()) {
            throw new \InvalidArgumentException('Opportunity is already closed.');
        }

        return DB::transaction(function () use ($opportunity, $reason) {
            // Find won stage
            $wonStage = PipelineStage::where('organization_id', $opportunity->organization_id)
                ->where('is_won', true)
                ->first();

            $opportunity->update([
                'status' => Opportunity::STATUS_WON,
                'pipeline_stage_id' => $wonStage?->id ?? $opportunity->pipeline_stage_id,
                'probability' => 100,
                'actual_close_date' => now(),
                'won_reason' => $reason,
            ]);

            // Log activity
            Activity::create([
                'organization_id' => $opportunity->organization_id,
                'activity_type' => Activity::TYPE_NOTE,
                'subject' => 'Opportunity won',
                'description' => $reason,
                'related_type' => Opportunity::class,
                'related_id' => $opportunity->id,
                'status' => Activity::STATUS_COMPLETED,
                'completed_at' => now(),
                'created_by' => auth()->id(),
            ]);

            return $opportunity->fresh();
        });
    }

    /**
     * Mark opportunity as lost.
     */
    public function lose(Opportunity $opportunity, ?string $reason = null): Opportunity
    {
        if ($opportunity->isClosed()) {
            throw new \InvalidArgumentException('Opportunity is already closed.');
        }

        return DB::transaction(function () use ($opportunity, $reason) {
            // Find lost stage
            $lostStage = PipelineStage::where('organization_id', $opportunity->organization_id)
                ->where('is_lost', true)
                ->first();

            $opportunity->update([
                'status' => Opportunity::STATUS_LOST,
                'pipeline_stage_id' => $lostStage?->id ?? $opportunity->pipeline_stage_id,
                'probability' => 0,
                'actual_close_date' => now(),
                'lost_reason' => $reason,
            ]);

            // Log activity
            Activity::create([
                'organization_id' => $opportunity->organization_id,
                'activity_type' => Activity::TYPE_NOTE,
                'subject' => 'Opportunity lost',
                'description' => $reason,
                'related_type' => Opportunity::class,
                'related_id' => $opportunity->id,
                'status' => Activity::STATUS_COMPLETED,
                'completed_at' => now(),
                'created_by' => auth()->id(),
            ]);

            return $opportunity->fresh();
        });
    }

    /**
     * Reopen a closed opportunity.
     */
    public function reopen(Opportunity $opportunity): Opportunity
    {
        if (!$opportunity->isClosed()) {
            throw new \InvalidArgumentException('Opportunity is not closed.');
        }

        $firstStage = PipelineStage::where('organization_id', $opportunity->organization_id)
            ->active()
            ->ordered()
            ->first();

        $opportunity->update([
            'status' => Opportunity::STATUS_OPEN,
            'pipeline_stage_id' => $firstStage?->id,
            'probability' => $firstStage?->probability ?? 10,
            'actual_close_date' => null,
            'won_reason' => null,
            'lost_reason' => null,
        ]);

        Activity::create([
            'organization_id' => $opportunity->organization_id,
            'activity_type' => Activity::TYPE_NOTE,
            'subject' => 'Opportunity reopened',
            'related_type' => Opportunity::class,
            'related_id' => $opportunity->id,
            'status' => Activity::STATUS_COMPLETED,
            'completed_at' => now(),
            'created_by' => auth()->id(),
        ]);

        return $opportunity->fresh();
    }

    /**
     * Assign opportunity to user.
     */
    public function assign(Opportunity $opportunity, int $userId): Opportunity
    {
        $opportunity->update(['assigned_to' => $userId]);

        return $opportunity->fresh();
    }

    /**
     * Get pipeline summary (stages with opportunities).
     */
    public function getPipelineSummary(): array
    {
        $stages = PipelineStage::active()
            ->ordered()
            ->withCount(['opportunities' => fn($q) => $q->open()])
            ->withSum(['opportunities' => fn($q) => $q->open()], 'amount')
            ->get();

        return $stages->map(fn($stage) => [
            'id' => $stage->id,
            'name' => $stage->name,
            'color' => $stage->color,
            'probability' => $stage->probability,
            'is_won' => $stage->is_won,
            'is_lost' => $stage->is_lost,
            'opportunity_count' => $stage->opportunities_count,
            'total_value' => (float) ($stage->opportunities_sum_amount ?? 0),
        ])->toArray();
    }

    /**
     * Get opportunity statistics.
     */
    public function getStatistics(?int $assignedTo = null): array
    {
        $query = Opportunity::query();

        if ($assignedTo) {
            $query->assignedTo($assignedTo);
        }

        $total = $query->count();
        $open = (clone $query)->open()->count();
        $won = (clone $query)->won()->count();
        $lost = (clone $query)->lost()->count();

        $openValue = (clone $query)->open()->sum('amount');
        $wonValue = (clone $query)->won()->sum('amount');
        $expectedRevenue = (clone $query)->open()->sum('expected_revenue');

        $closingThisMonth = (clone $query)->closingThisMonth()->count();
        $overdue = (clone $query)->overdue()->count();

        $winRate = ($won + $lost) > 0 ? round(($won / ($won + $lost)) * 100, 2) : 0;

        // Average deal size
        $avgDealSize = $won > 0 ? $wonValue / $won : 0;

        // Average sales cycle (days)
        $avgSalesCycle = Opportunity::where('status', Opportunity::STATUS_WON)
            ->whereNotNull('actual_close_date')
            ->selectRaw('AVG(DATEDIFF(actual_close_date, created_at)) as avg_days')
            ->first()->avg_days ?? 0;

        return [
            'total' => $total,
            'open' => $open,
            'won' => $won,
            'lost' => $lost,
            'open_value' => (float) $openValue,
            'won_value' => (float) $wonValue,
            'expected_revenue' => (float) $expectedRevenue,
            'closing_this_month' => $closingThisMonth,
            'overdue' => $overdue,
            'win_rate' => $winRate,
            'avg_deal_size' => round((float) $avgDealSize, 2),
            'avg_sales_cycle_days' => round((float) $avgSalesCycle, 0),
        ];
    }

    /**
     * Get forecast by month.
     */
    public function getForecast(int $months = 6): array
    {
        $forecast = [];
        $startDate = now()->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            $monthStart = $startDate->copy()->addMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();

            $opportunities = Opportunity::open()
                ->whereBetween('expected_close_date', [$monthStart, $monthEnd])
                ->get();

            $forecast[] = [
                'month' => $monthStart->format('Y-m'),
                'month_name' => $monthStart->format('F Y'),
                'count' => $opportunities->count(),
                'total_value' => $opportunities->sum('amount'),
                'weighted_value' => $opportunities->sum('expected_revenue'),
            ];
        }

        return $forecast;
    }
}
