<?php

declare(strict_types=1);

namespace App\Listeners\CRM;

use App\Events\CRM\OpportunityWon;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateOpportunityFromWonListener implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(OpportunityWon $event): void
    {
        $opportunity = $event->opportunity;

        // Update contact's last won opportunity date
        if ($opportunity->contact) {
            $opportunity->contact->update([
                'last_won_opportunity_at' => now(),
                'total_won_value' => bcadd(
                    (string) ($opportunity->contact->total_won_value ?? 0),
                    (string) $opportunity->amount,
                    4
                ),
            ]);
        }

        // Log the win for analytics
        \Log::info('Opportunity won', [
            'opportunity_id' => $opportunity->id,
            'opportunity_number' => $opportunity->opportunity_number,
            'contact_id' => $opportunity->contact_id,
            'amount' => $opportunity->amount,
            'won_reason' => $event->wonReason,
        ]);

        // Here you could also:
        // - Create a sales order
        // - Trigger a workflow
        // - Send notifications to stakeholders
    }
}
