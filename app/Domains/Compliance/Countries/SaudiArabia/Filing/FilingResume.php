<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;

/**
 * A device paused for refused credentials (FilingPause) files again once
 * they are renewed: it is resumed, and what it signed and has not filed is
 * put in the offline queue, whose drain files it.
 */
class FilingResume
{
    public function __construct(
        private readonly Tills $tills,
        private readonly UnfiledDocuments $unfiled,
        private readonly OfflineQueue $offline,
        private readonly AuditService $audit,
    ) {}

    public function resume(string $organizationId, string $unitId): void
    {
        if (! $this->tills->resumeFiling($organizationId, $unitId)) {
            return;
        }

        $organization = Organization::query()->find($organizationId);

        if ($organization !== null) {
            $this->audit->logFor($organizationId, 'zatca.filing.resumed', $organization, ['device_id' => $unitId]);
        }

        foreach ($this->unfiled->signedBy($organizationId, $unitId) as $document) {
            $invoice = Invoice::acrossTenants()->find($document['id']);

            if ($invoice !== null && $invoice->signed_xml !== null && ! $this->offline->isQueued($invoice)) {
                $this->offline->queue($invoice, (string) $invoice->signed_xml, (string) $invoice->hash, (string) $invoice->qr_code);
            }
        }
    }
}
