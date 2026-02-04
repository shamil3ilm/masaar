<?php

declare(strict_types=1);

namespace App\Services\CRM;

use App\Models\CRM\Activity;
use App\Models\CRM\Lead;
use App\Models\CRM\Opportunity;
use App\Models\CRM\PipelineStage;
use App\Models\Sales\Contact;
use App\Services\Core\NumberGeneratorService;
use Illuminate\Support\Facades\DB;

class LeadService
{
    public function __construct(
        private NumberGeneratorService $numberGenerator
    ) {}

    /**
     * Create a new lead.
     */
    public function create(array $data): Lead
    {
        return DB::transaction(function () use ($data) {
            if (empty($data['lead_number'])) {
                $data['lead_number'] = $this->numberGenerator->generate('LEAD');
            }

            $data['status'] = $data['status'] ?? Lead::STATUS_NEW;
            $data['rating'] = $data['rating'] ?? Lead::RATING_COLD;
            $data['lead_score'] = $data['lead_score'] ?? $this->calculateInitialScore($data);

            $lead = Lead::create($data);

            // Create initial activity
            if (!empty($data['assigned_to'])) {
                Activity::create([
                    'organization_id' => $lead->organization_id,
                    'activity_type' => Activity::TYPE_TASK,
                    'subject' => 'Initial contact with new lead',
                    'related_type' => Lead::class,
                    'related_id' => $lead->id,
                    'assigned_to' => $data['assigned_to'],
                    'status' => Activity::STATUS_PLANNED,
                    'priority' => Activity::PRIORITY_MEDIUM,
                    'start_datetime' => now()->addDay(),
                    'created_by' => auth()->id(),
                ]);
            }

            return $lead;
        });
    }

    /**
     * Update a lead.
     */
    public function update(Lead $lead, array $data): Lead
    {
        if ($lead->isConverted()) {
            throw new \InvalidArgumentException('Converted leads cannot be updated.');
        }

        $lead->update($data);

        // Recalculate score if relevant fields changed
        if (isset($data['estimated_value']) || isset($data['industry']) || isset($data['employee_count'])) {
            $lead->lead_score = $this->calculateScore($lead);
            $lead->save();
        }

        return $lead->fresh();
    }

    /**
     * Change lead status.
     */
    public function changeStatus(Lead $lead, string $status, ?string $reason = null): Lead
    {
        if ($lead->isConverted()) {
            throw new \InvalidArgumentException('Converted leads cannot change status.');
        }

        $lead->status = $status;

        if ($status === Lead::STATUS_LOST && $reason) {
            $lead->lost_reason = $reason;
        }

        $lead->save();

        return $lead->fresh();
    }

    /**
     * Convert lead to customer and optionally create opportunity.
     */
    public function convert(Lead $lead, bool $createOpportunity = true, ?array $opportunityData = null): array
    {
        if (!$lead->canBeConverted()) {
            throw new \InvalidArgumentException('Only qualified leads can be converted.');
        }

        return DB::transaction(function () use ($lead, $createOpportunity, $opportunityData) {
            // Create contact
            $contact = Contact::create([
                'organization_id' => $lead->organization_id,
                'contact_type' => 'customer',
                'company_name' => $lead->company_name,
                'contact_name' => $lead->contact_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'website' => $lead->website,
                'billing_address_line_1' => $lead->address_line_1,
                'billing_address_line_2' => $lead->address_line_2,
                'billing_city' => $lead->city,
                'billing_state' => $lead->state,
                'billing_postal_code' => $lead->postal_code,
                'billing_country_code' => $lead->country_code,
                'currency_code' => $lead->currency_code,
            ]);

            $opportunity = null;

            if ($createOpportunity) {
                $firstStage = PipelineStage::where('organization_id', $lead->organization_id)
                    ->active()
                    ->ordered()
                    ->first();

                $opportunity = Opportunity::create([
                    'organization_id' => $lead->organization_id,
                    'opportunity_number' => $this->numberGenerator->generate('OPP'),
                    'name' => $opportunityData['name'] ?? "Opportunity from {$lead->getDisplayName()}",
                    'contact_id' => $contact->id,
                    'lead_id' => $lead->id,
                    'account_name' => $lead->company_name,
                    'pipeline_stage_id' => $firstStage?->id,
                    'probability' => $firstStage?->probability ?? 10,
                    'amount' => $opportunityData['amount'] ?? $lead->estimated_value,
                    'currency_code' => $lead->currency_code,
                    'expected_close_date' => $opportunityData['expected_close_date'] ?? now()->addDays(30),
                    'assigned_to' => $lead->assigned_to,
                    'branch_id' => $lead->branch_id,
                    'lead_source_id' => $lead->lead_source_id,
                    'status' => Opportunity::STATUS_OPEN,
                    'created_by' => auth()->id(),
                ]);
            }

            // Update lead
            $lead->update([
                'status' => Lead::STATUS_CONVERTED,
                'converted_contact_id' => $contact->id,
                'converted_opportunity_id' => $opportunity?->id,
                'converted_at' => now(),
                'converted_by' => auth()->id(),
            ]);

            return [
                'lead' => $lead->fresh(),
                'contact' => $contact,
                'opportunity' => $opportunity,
            ];
        });
    }

    /**
     * Calculate initial lead score based on data.
     */
    protected function calculateInitialScore(array $data): int
    {
        $score = 0;

        // Email provided
        if (!empty($data['email'])) {
            $score += 10;
        }

        // Phone provided
        if (!empty($data['phone']) || !empty($data['mobile'])) {
            $score += 10;
        }

        // Company info
        if (!empty($data['company_name'])) {
            $score += 10;
        }

        // Estimated value
        if (!empty($data['estimated_value']) && $data['estimated_value'] > 0) {
            $score += min(30, (int) ($data['estimated_value'] / 10000));
        }

        // Employee count indicates company size
        if (!empty($data['employee_count'])) {
            if ($data['employee_count'] > 100) {
                $score += 20;
            } elseif ($data['employee_count'] > 50) {
                $score += 15;
            } elseif ($data['employee_count'] > 10) {
                $score += 10;
            }
        }

        return min(100, $score);
    }

    /**
     * Calculate lead score from existing lead.
     */
    protected function calculateScore(Lead $lead): int
    {
        return $this->calculateInitialScore($lead->toArray());
    }

    /**
     * Assign lead to user.
     */
    public function assign(Lead $lead, int $userId): Lead
    {
        $lead->update(['assigned_to' => $userId]);

        Activity::create([
            'organization_id' => $lead->organization_id,
            'activity_type' => Activity::TYPE_TASK,
            'subject' => 'Follow up on assigned lead',
            'related_type' => Lead::class,
            'related_id' => $lead->id,
            'assigned_to' => $userId,
            'status' => Activity::STATUS_PLANNED,
            'priority' => Activity::PRIORITY_MEDIUM,
            'start_datetime' => now()->addDay(),
            'created_by' => auth()->id(),
        ]);

        return $lead->fresh();
    }

    /**
     * Get lead statistics.
     */
    public function getStatistics(?int $assignedTo = null): array
    {
        $query = Lead::query();

        if ($assignedTo) {
            $query->assignedTo($assignedTo);
        }

        $total = $query->count();
        $new = (clone $query)->new()->count();
        $open = (clone $query)->open()->count();
        $converted = (clone $query)->converted()->count();
        $lost = (clone $query)->lost()->count();

        $bySource = Lead::selectRaw('lead_source_id, count(*) as count')
            ->groupBy('lead_source_id')
            ->with('leadSource:id,name')
            ->get()
            ->mapWithKeys(fn($row) => [$row->leadSource?->name ?? 'Unknown' => $row->count]);

        $byRating = Lead::selectRaw('rating, count(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating');

        $conversionRate = $total > 0 ? round(($converted / $total) * 100, 2) : 0;

        return [
            'total' => $total,
            'new' => $new,
            'open' => $open,
            'converted' => $converted,
            'lost' => $lost,
            'conversion_rate' => $conversionRate,
            'by_source' => $bySource,
            'by_rating' => $byRating,
        ];
    }
}
