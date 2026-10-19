<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Services;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\MissingText;
use App\Domains\Organization\Models\Organization;

/**
 * Shapes what the pipeline returns to the calling ERP.
 *
 * Separate from the orchestration because this is the integration contract:
 * other systems parse these keys, so the shape changes only with a version,
 * while the steps that produce it can be reworked freely.
 */
class PipelineResult
{
    public function __construct(
        private readonly MissingText $missing,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function build(Invoice $invoice, array $errors, array $warnings, ?array $zatcaResponse): array
    {
        $organization = Organization::query()->find($invoice->org_id);
        $rejections = $this->jurisdictions->forRules($organization)->notices()->rejections($invoice);

        return [
            'invoice_id' => $invoice->id,
            'uuid' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'erp_reference_id' => $invoice->erp_reference_id,
            'status' => $invoice->status->value,
            'compliance_status' => $this->complianceStatus($invoice->status, $zatcaResponse),
            'hash' => $invoice->hash,
            'qr_code' => $invoice->qr_code,
            // The document of record. For a cleared standard invoice that is
            // ZATCA's stamped copy, which is the legal invoice; otherwise it
            // is what we signed. cleared_xml says which one arrived, so a
            // caller that needs to tell them apart can.
            'signed_xml' => $invoice->legal_xml,
            'cleared_xml' => $invoice->cleared_xml,
            'zatca_response' => $zatcaResponse,
            'totals' => [
                'subtotal' => $invoice->subtotal,
                'discount_amount' => $invoice->discount_amount,
                'tax_amount' => $invoice->tax_amount,
                'total' => $invoice->total,
            ],
            'errors' => $errors,
            // The authority's, as this filing answered or as the invoice
            // kept them - so a replay repeats them - and the text the
            // invoice lacks that a grace period let through (MissingText).
            'warnings' => array_values(array_unique([
                ...$warnings,
                ...$this->jurisdictions->forRules($organization)->notices()->advisories($invoice),
                ...$this->missing->warnings($invoice, $organization),
            ])),
            // A rejected invoice's rules, each by code, category and
            // message; only then, so every other answer keeps its keys.
            ...($rejections === [] ? [] : ['rejections' => $rejections]),
        ];
    }

    /**
     * The ERP-facing status: cleared, reported, rejected or pending.
     *
     * Deliberately coarser than InvoiceStatus. An ERP needs to know whether the
     * document is filed and how, not which internal state it passed through.
     *
     * Cleared and reported both come from Accepted: ZATCA clears standard
     * (B2B) invoices before they are issued and only receives a report of
     * simplified (B2C) ones after, so the presence of a clearance status is
     * what separates them.
     */
    private function complianceStatus(InvoiceStatus $status, ?array $zatcaResponse): string
    {
        return match ($status) {
            InvoiceStatus::Accepted => isset($zatcaResponse['clearance_status']) ? 'cleared' : 'reported',
            InvoiceStatus::Rejected => 'rejected',
            default => 'pending',
        };
    }
}
