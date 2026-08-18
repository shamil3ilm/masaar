<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Services;

use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;

/**
 * Shapes what the pipeline returns to the calling ERP.
 *
 * Separate from the orchestration because this is the integration contract:
 * other systems parse these keys, so the shape changes only with a version,
 * while the steps that produce it can be reworked freely.
 */
class PipelineResult
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function build(Invoice $invoice, array $errors, array $warnings, ?array $zatcaResponse): array
    {
        return [
            'invoice_id' => $invoice->id,
            'uuid' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'erp_reference_id' => $invoice->erp_reference_id,
            'status' => $invoice->status->value,
            'compliance_status' => $this->complianceStatus($invoice->status, $zatcaResponse),
            'hash' => $invoice->hash,
            'qr_code' => $invoice->qr_code,
            'signed_xml' => $invoice->signed_xml,
            'zatca_response' => $zatcaResponse,
            'totals' => [
                'subtotal' => $invoice->subtotal,
                'discount_amount' => $invoice->discount_amount,
                'tax_amount' => $invoice->tax_amount,
                'total' => $invoice->total,
            ],
            'errors' => $errors,
            'warnings' => $warnings,
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
