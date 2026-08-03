<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Services\FatooraSubmissionService;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline service for atomic ERP-ZATCA invoice submission.
 *
 * Orchestrates the full lifecycle in a single call:
 * 1. Create invoice in ZATCA DB
 * 2. Generate compliance data (hash, QR, signed XML)
 * 3. Optionally submit to ZATCA government API
 * 4. Dispatch webhook notifications
 *
 * Designed for server-to-server ERP integration where the caller
 * needs a single atomic endpoint instead of 3 separate calls.
 */
class PipelineService
{
    public function __construct(
        private readonly FatooraSubmissionService $submissionService,
        private readonly WebhookService $webhookService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Submit an invoice through the full pipeline.
     *
     * @param array $data Validated request data
     * @param string $organizationId Organization UUID
     * @param string|null $branchId Optional branch UUID
     * @return array Pipeline result with invoice data, compliance info, and ZATCA response
     */
    public function submitInvoice(array $data, string $organizationId, ?string $branchId = null): array
    {
        $errors = [];
        $warnings = [];
        $zatcaResponse = null;
        $autoSubmit = (bool) ($data['auto_submit'] ?? true);

        $organization = Organization::findOrFail($organizationId);

        // Note: branch_id is accepted but not stored on the invoice (no branch_id column).
        // FatooraSubmissionService falls back to org-level credentials when branch is null.
        // Branch validation is skipped here intentionally until branch migration is added.

        // Step 1: Create invoice and lines within a transaction
        // erp_reference_id is now persisted via the invoices.erp_reference_id column.
        $invoice = $this->createInvoice($data, $organizationId);

        // Step 2: Generate compliance data (hash, QR, signed XML)
        try {
            $complianceResult = $this->submissionService->generate($invoice, $organization);

            // Refresh invoice to get updated fields
            $invoice->refresh();

            // Dispatch webhook for invoice issued
            $this->dispatchWebhookSafely(
                $organizationId,
                'invoice.issued',
                $this->buildWebhookPayload($invoice)
            );
        } catch (FatooraException $e) {
            Log::error('Pipeline: compliance generation failed', [
                'invoice_id' => $invoice->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            $errors[] = 'Compliance generation failed: ' . $e->getMessage();

            return $this->buildResult($invoice, $errors, $warnings, null);
        } catch (\Exception $e) {
            Log::error('Pipeline: unexpected error during compliance generation', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            $errors[] = 'Unexpected error during compliance generation: ' . $e->getMessage();

            return $this->buildResult($invoice, $errors, $warnings, null);
        }

        // Step 3: Submit to ZATCA government API if auto_submit is enabled
        if ($autoSubmit) {
            try {
                $response = $this->submissionService->submit($invoice, $organization);
                $invoice->refresh();

                $zatcaResponse = [
                    'clearance_status' => $response->clearanceStatus,
                    'reporting_status' => $response->reportingStatus,
                    'validation_status' => $response->validationStatus,
                ];

                $warnings = $response->warningMessages ?? [];

                if (!$response->success) {
                    $errors = array_merge($errors, $response->errorMessages ?? []);

                    // Dispatch rejection webhook
                    $this->dispatchWebhookSafely(
                        $organizationId,
                        'invoice.rejected',
                        $this->buildWebhookPayload($invoice, ['errors' => $errors])
                    );
                } else {
                    // Dispatch appropriate success webhook
                    $event = $invoice->requiresClearance()
                        ? 'invoice.cleared'
                        : 'invoice.reported';

                    $this->dispatchWebhookSafely(
                        $organizationId,
                        $event,
                        $this->buildWebhookPayload($invoice, ['warnings' => $warnings])
                    );
                }
            } catch (FatooraException $e) {
                Log::warning('Pipeline: ZATCA submission failed, invoice remains issued', [
                    'invoice_id' => $invoice->id,
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                    'retryable' => $e->isRetryable(),
                ]);

                $errors[] = 'ZATCA submission failed: ' . $e->getMessage();
                $invoice->refresh();
            } catch (\Exception $e) {
                Log::error('Pipeline: unexpected error during ZATCA submission', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);

                $errors[] = 'Unexpected error during ZATCA submission: ' . $e->getMessage();
                $invoice->refresh();
            }
        }

        return $this->buildResult($invoice, $errors, $warnings, $zatcaResponse);
    }

    /**
     * Create an invoice with lines using bcmath for monetary precision.
     *
     * Runs inside a DB transaction to ensure atomicity.
     */
    private function createInvoice(array $data, string $organizationId): Invoice
    {
        return DB::transaction(function () use ($data, $organizationId) {
            $invoice = Invoice::create([
                'organization_id' => $organizationId,
                'invoice_number' => $data['invoice_number'],
                'type' => $data['type'],
                'document_type' => $data['document_type'],
                'status' => InvoiceStatus::Draft,
                'issue_date' => $data['issue_date'],
                'supply_date' => $data['supply_date'] ?? null,
                'currency' => $data['currency'] ?? 'SAR',
                'payment_means_code' => $data['payment_means_code'] ?? '10',
                'buyer_name' => $data['buyer_name'],
                'buyer_vat_number' => $data['buyer_vat_number'] ?? null,
                'buyer_address' => $data['buyer_address'] ?? null,
                'billing_reference_id' => $data['billing_reference_id'] ?? null,
                'adjustment_reason' => $data['adjustment_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'erp_reference_id' => $data['erp_reference_id'] ?? null,
            ]);

            // Calculate line totals using bcmath for precision
            $subtotal = '0';
            $taxTotal = '0';
            $discountAmount = (string) ($data['discount_amount'] ?? '0');

            foreach ($data['lines'] as $line) {
                $quantity = (string) $line['quantity'];
                $unitPrice = (string) $line['unit_price'];
                $taxRate = (string) ($line['tax_rate'] ?? 15);

                $lineSubtotal = bcmul($quantity, $unitPrice, 2);
                $lineTax = bcdiv(bcmul($lineSubtotal, $taxRate, 4), '100', 2);
                $lineTotal = bcadd($lineSubtotal, $lineTax, 2);

                $invoice->lines()->create([
                    'description' => $line['description'],
                    'item_classification_code' => $line['item_classification_code'] ?? null,
                    'quantity' => $quantity,
                    'unit_code' => $line['unit_code'] ?? 'PCE',
                    'unit_price' => $unitPrice,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $lineTax,
                    'tax_category' => $line['tax_category'] ?? 'S',
                    'tax_exemption_code' => $line['tax_exemption_code'] ?? null,
                    'tax_exemption_reason' => $line['tax_exemption_reason'] ?? null,
                    'line_total' => $lineTotal,
                ]);

                $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                $taxTotal = bcadd($taxTotal, $lineTax, 2);
            }

            $total = bcadd(bcsub($subtotal, $discountAmount, 2), $taxTotal, 2);

            $invoice->update([
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxTotal,
                'total' => $total,
            ]);

            $this->auditService->logCreated($invoice);

            return $invoice;
        });
    }

    /**
     * Build the pipeline result array.
     */
    private function buildResult(
        Invoice $invoice,
        array $errors,
        array $warnings,
        ?array $zatcaResponse,
    ): array {
        return [
            'invoice_id' => $invoice->id,
            'uuid' => $invoice->uuid ?? $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'erp_reference_id' => $invoice->erp_reference_id,
            'status' => $invoice->status->value,
            'compliance_status' => $this->deriveComplianceStatus($invoice->status, $zatcaResponse),
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
     * Derive a ERP-facing compliance status from the ZATCA invoice status and response.
     *
     * Maps internal InvoiceStatus enum values to the agreed integration contract:
     * cleared | reported | rejected | pending
     */
    private function deriveComplianceStatus(InvoiceStatus $status, ?array $zatcaResponse): string
    {
        return match ($status) {
            InvoiceStatus::Accepted => isset($zatcaResponse['clearance_status'])
                ? 'cleared'
                : 'reported',
            InvoiceStatus::Rejected => 'rejected',
            default => 'pending',
        };
    }

    /**
     * Build webhook payload for an invoice event.
     */
    private function buildWebhookPayload(Invoice $invoice, array $extra = []): array
    {
        $payload = [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'type' => $invoice->type->value,
            'total' => $invoice->total,
            'currency' => $invoice->currency,
        ];

        return array_merge($payload, $extra);
    }

    /**
     * Dispatch a webhook event, swallowing exceptions to avoid breaking the pipeline.
     *
     * Webhook delivery is best-effort; a failed webhook must never
     * abort the invoice submission pipeline.
     */
    private function dispatchWebhookSafely(string $organizationId, string $event, array $payload): void
    {
        try {
            $this->webhookService->dispatch($organizationId, $event, $payload);
        } catch (\Exception $e) {
            Log::warning('Pipeline: webhook dispatch failed', [
                'organization_id' => $organizationId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
