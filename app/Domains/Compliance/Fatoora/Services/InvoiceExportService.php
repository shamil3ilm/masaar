<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Invoice Export Service.
 *
 * Handles exports with proper compliance watermarking.
 *
 * POLICY: Non-compliant invoices can be exported for audit purposes
 * but MUST be clearly watermarked to prevent misuse.
 *
 * Export modes:
 * - compliant: Standard export for cleared/reported invoices
 * - audit: Watermarked export for rejected/non-compliant invoices
 * - draft: Export of draft invoices (not submitted)
 */
class InvoiceExportService
{
    /**
     * Watermark text for non-compliant exports.
     */
    private const NON_COMPLIANT_WATERMARK = '*** NON-COMPLIANT - NOT CLEARED BY ZATCA ***';
    private const DRAFT_WATERMARK = '*** DRAFT - NOT SUBMITTED TO ZATCA ***';
    private const REJECTED_WATERMARK = '*** REJECTED BY ZATCA - DO NOT USE FOR TAX PURPOSES ***';

    /**
     * Export an invoice with appropriate watermarking.
     *
     * @param string $invoiceId
     * @param string $requestedBy Who is requesting the export
     * @param string|null $reason Reason for export (required for non-compliant)
     * @return array Export data with watermarks and audit trail
     */
    public function export(string $invoiceId, string $requestedBy, ?string $reason = null): array
    {
        $invoice = Invoice::with(['lines', 'organization'])->find($invoiceId);

        if (!$invoice) {
            return ['error' => 'Invoice not found'];
        }

        $complianceStatus = $this->determineComplianceStatus($invoice);
        $exportMode = $this->determineExportMode($complianceStatus);

        // Require reason for non-standard exports
        if ($exportMode !== 'compliant' && empty($reason)) {
            return [
                'error' => 'Reason required for non-compliant export',
                'export_mode' => $exportMode,
                'compliance_status' => $complianceStatus,
            ];
        }

        // Log the export request
        $this->logExportRequest($invoice, $exportMode, $requestedBy, $reason);

        // Generate export data
        $exportData = $this->generateExportData($invoice, $exportMode);

        return [
            'success' => true,
            'export_mode' => $exportMode,
            'compliance_status' => $complianceStatus,
            'watermark' => $exportData['watermark'],
            'data' => $exportData['data'],
            'exported_at' => now()->toIso8601String(),
            'exported_by' => $requestedBy,
            'audit_id' => $exportData['audit_id'],
        ];
    }

    /**
     * Determine the compliance status of an invoice.
     */
    private function determineComplianceStatus(Invoice $invoice): string
    {
        // Check submission status
        $submission = DB::table('invoice_submissions')
            ->where('invoice_id', $invoice->id)
            ->orderBy('submitted_at', 'desc')
            ->first();

        if (!$submission) {
            return $invoice->status === 'draft' ? 'draft' : 'not_submitted';
        }

        $clearanceState = $submission->clearance_state ?? 'unknown';

        return match ($clearanceState) {
            'cleared', 'reported' => 'compliant',
            'rejected' => 'rejected',
            'timeout' => 'unconfirmed',
            default => 'pending',
        };
    }

    /**
     * Determine export mode based on compliance status.
     */
    private function determineExportMode(string $complianceStatus): string
    {
        return match ($complianceStatus) {
            'compliant' => 'compliant',
            'draft' => 'draft',
            'rejected' => 'audit',
            'not_submitted' => 'audit',
            'unconfirmed' => 'audit',
            'pending' => 'audit',
            default => 'audit',
        };
    }

    /**
     * Generate export data with appropriate watermarking.
     */
    private function generateExportData(Invoice $invoice, string $exportMode): array
    {
        $watermark = $this->getWatermark($exportMode, $invoice);

        $data = [
            'watermark_header' => $watermark,
            'export_disclaimer' => $this->getDisclaimer($exportMode),
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'type' => $invoice->type,
                'document_type' => $invoice->document_type,
                'status' => $invoice->status,
                'issue_date' => $invoice->issue_date,
                'supply_date' => $invoice->supply_date,
                'currency' => $invoice->currency,
                'subtotal' => $invoice->subtotal,
                'tax_amount' => $invoice->tax_amount,
                'total' => $invoice->total,
                'buyer_name' => $invoice->buyer_name,
                'buyer_vat_number' => $invoice->buyer_vat_number,
            ],
            'seller' => [
                'name' => $invoice->organization->name ?? 'Unknown',
                'vat_number' => $invoice->organization->vat_number ?? 'Unknown',
            ],
            'lines' => $invoice->lines->map(fn($line) => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'tax_amount' => $line->tax_amount,
                'line_total' => $line->line_total,
            ])->toArray(),
            'compliance_metadata' => [
                'icv' => $invoice->icv,
                'hash' => $invoice->hash,
                'rule_version' => $invoice->rule_version,
                'schema_version' => $invoice->schema_version,
            ],
            'watermark_footer' => $watermark,
        ];

        // For non-compliant exports, add rejection details
        if ($exportMode === 'audit') {
            $submission = DB::table('invoice_submissions')
                ->where('invoice_id', $invoice->id)
                ->orderBy('submitted_at', 'desc')
                ->first();

            if ($submission) {
                $data['rejection_details'] = [
                    'clearance_state' => $submission->clearance_state ?? 'unknown',
                    'zatca_response' => $submission->zatca_response
                        ? json_decode($submission->zatca_response, true)
                        : null,
                ];
            }
        }

        // Generate audit ID
        $auditId = $this->generateAuditId($invoice->id, $exportMode);

        return [
            'watermark' => $watermark,
            'data' => $data,
            'audit_id' => $auditId,
        ];
    }

    /**
     * Get watermark text based on export mode.
     */
    private function getWatermark(string $exportMode, Invoice $invoice): ?string
    {
        return match ($exportMode) {
            'compliant' => null,
            'draft' => self::DRAFT_WATERMARK,
            'audit' => $this->getAuditWatermark($invoice),
            default => self::NON_COMPLIANT_WATERMARK,
        };
    }

    /**
     * Get specific watermark for audit exports.
     */
    private function getAuditWatermark(Invoice $invoice): string
    {
        $submission = DB::table('invoice_submissions')
            ->where('invoice_id', $invoice->id)
            ->orderBy('submitted_at', 'desc')
            ->first();

        if ($submission && ($submission->clearance_state ?? '') === 'rejected') {
            return self::REJECTED_WATERMARK;
        }

        return self::NON_COMPLIANT_WATERMARK;
    }

    /**
     * Get disclaimer text for export mode.
     */
    private function getDisclaimer(string $exportMode): string
    {
        return match ($exportMode) {
            'compliant' => 'This invoice has been cleared/reported by ZATCA and is valid for tax purposes.',
            'draft' => 'This is a draft invoice that has not been submitted to ZATCA. It is not valid for tax purposes.',
            'audit' => 'This invoice export is for AUDIT PURPOSES ONLY. It has NOT been cleared by ZATCA ' .
                       'and MUST NOT be used for tax deduction, reimbursement, or any official purpose. ' .
                       'Using this document for tax purposes may constitute fraud.',
            default => 'Document status unknown. Verify with ZATCA before use.',
        };
    }

    /**
     * Log export request for audit trail.
     */
    private function logExportRequest(
        Invoice $invoice,
        string $exportMode,
        string $requestedBy,
        ?string $reason
    ): void {
        DB::table('audit_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'auditable_type' => Invoice::class,
            'auditable_id' => $invoice->id,
            'event' => 'export',
            'old_values' => json_encode([]),
            'new_values' => json_encode([
                'export_mode' => $exportMode,
                'reason' => $reason,
            ]),
            'user_id' => null,
            'user_type' => null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'tags' => json_encode(['export', $exportMode]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('Invoice export requested', [
            'invoice_id' => $invoice->id,
            'export_mode' => $exportMode,
            'requested_by' => $requestedBy,
            'reason' => $reason,
            'is_non_compliant' => $exportMode !== 'compliant',
        ]);
    }

    /**
     * Generate a unique audit ID for the export.
     */
    private function generateAuditId(string $invoiceId, string $exportMode): string
    {
        return sprintf(
            'EXP-%s-%s-%s',
            strtoupper(substr($invoiceId, 0, 8)),
            strtoupper($exportMode[0]),
            now()->format('YmdHis')
        );
    }
}
