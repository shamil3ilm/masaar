<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Models\ChainEntry;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Models\OfflineItem;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Seeds the rows the platform screens count, for several tenants at once.
 *
 * Attributes are force-filled on creation: authorship and timestamps are what
 * these screens group by, and a submission in a terminal state refuses any
 * later edit.
 */
trait PlatformRows
{
    private int $rowNumber = 0;

    private function organization(string $name): Organization
    {
        return Organization::create(['name' => $name, 'country' => 'SA']);
    }

    private function invoice(Organization $organization, array $attributes = []): Invoice
    {
        $this->rowNumber++;

        $invoice = (new Invoice)->forceFill([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-'.$this->rowNumber,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
            ...$attributes,
        ]);

        $invoice->save();

        return $invoice;
    }

    private function submission(Organization $organization, string $state, array $attributes = []): InvoiceSubmission
    {
        $submission = (new InvoiceSubmission)->forceFill([
            'invoice_id' => $attributes['invoice_id'] ?? $this->invoice($organization)->id,
            'org_id' => $organization->id,
            'state' => $state,
            'submission_type' => 'clearance',
            ...$attributes,
        ]);

        $submission->save();

        return $submission;
    }

    private function queueItem(Organization $organization, string $state, array $attributes = []): OfflineItem
    {
        $item = (new OfflineItem)->forceFill([
            'invoice_id' => $attributes['invoice_id'] ?? $this->invoice($organization)->id,
            'org_id' => $organization->id,
            'signed_xml' => '<Invoice/>',
            'invoice_hash' => str_repeat('a', 64),
            'qr_code' => 'qr',
            'state' => $state,
            'queued_at' => now(),
            ...$attributes,
        ]);

        $item->save();

        return $item;
    }

    private function chainEntry(Organization $organization, int $icv, array $attributes = []): ChainEntry
    {
        $entry = (new ChainEntry)->forceFill([
            'org_id' => $organization->id,
            'invoice_id' => $this->invoice($organization)->id,
            'invoice_hash' => str_repeat('b', 64),
            'previous_hash' => str_repeat('c', 64),
            'icv' => $icv,
            'certificate_id' => str_repeat('0', 64),
            ...$attributes,
        ]);

        $entry->save();

        return $entry;
    }

    private function platformToken(): string
    {
        return JWTAuth::fromUser(User::factory()->platformAdmin()->create());
    }
}
