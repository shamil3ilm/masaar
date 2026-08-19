<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\Services;

use App\Domains\Compliance\FTA\DTOs\FtaInvoiceData;
use App\Domains\Compliance\FTA\DTOs\FtaResponse;
use App\Domains\Compliance\FTA\Enums\FtaStatus;
use App\Domains\Compliance\FTA\Exceptions\FtaException;
use App\Domains\Compliance\FTA\Models\FtaSubmission;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * UAE FTA e-Invoicing Service.
 *
 * Orchestrates the full Peppol BIS Billing 3.0 submission workflow:
 * 1. Build UBL XML
 * 2. Validate against FTA rules
 * 3. Submit to FTA API
 * 4. Track status
 *
 * Simpler than ZATCA — no CSID onboarding, no XAdES signing, no QR code.
 * All B2B and B2C invoices go through the same Peppol submission endpoint.
 */
class FtaService
{
    public function __construct(
        private readonly FtaXmlBuilder $xmlBuilder,
        private readonly FtaValidator $validator,
    ) {}

    /**
     * Generate and submit a UAE FTA e-invoice.
     */
    public function submit(Invoice $invoice, Organization $organization): FtaSubmission
    {
        $data = $this->buildInvoiceData($invoice, $organization);

        $this->validator->validate($data);

        $xml = $this->xmlBuilder->build($data);

        $submission = FtaSubmission::create([
            'invoice_id' => $invoice->id,
            'org_id' => $organization->id,
            'status' => FtaStatus::Queued,
            'document_type' => $data->documentType,
            'invoice_xml' => $xml,
            'max_retries' => config('fta.retry.max_attempts', 5),
            'retry_count' => 0,
        ]);

        return $this->dispatch($submission);
    }

    /**
     * Re-submit a failed/rejected submission.
     */
    public function retry(FtaSubmission $submission): FtaSubmission
    {
        if (! $submission->canRetry()) {
            throw FtaException::invalidState($submission->status->value, 'queued');
        }

        $submission->update([
            'status' => FtaStatus::Queued,
            'retry_count' => $submission->retry_count + 1,
        ]);

        return $this->dispatch($submission);
    }

    /**
     * Check submission status from FTA (for pending_review submissions).
     */
    public function checkStatus(FtaSubmission $submission): FtaSubmission
    {
        if ($submission->status !== FtaStatus::PendingReview || $submission->reference === null) {
            return $submission;
        }

        try {
            $response = Http::withToken($this->getApiKey())
                ->timeout(config('fta.timeout', 30))
                ->get($this->getBaseUrl()."/submissions/{$submission->reference}/status");

            if ($response->successful()) {
                $ftaStatus = $response->json('status');

                $newStatus = match ($ftaStatus) {
                    'accepted' => FtaStatus::Accepted,
                    'rejected' => FtaStatus::Rejected,
                    default => FtaStatus::PendingReview,
                };

                $submission->update([
                    'status' => $newStatus,
                    'errors' => $response->json('errors', []),
                    'accepted_at' => $newStatus === FtaStatus::Accepted ? now() : null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('UAE FTA status check failed', ['submission_id' => $submission->id, 'error' => $e->getMessage()]);
        }

        return $submission->fresh();
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    private function dispatch(FtaSubmission $submission): FtaSubmission
    {
        try {
            $submission->update(['status' => FtaStatus::Submitted, 'submitted_at' => now()]);

            $response = Http::withToken($this->getApiKey())
                ->timeout(config('fta.timeout', 30))
                ->withHeaders(['Content-Type' => 'application/xml', 'Accept' => 'application/json'])
                ->withBody($submission->invoice_xml, 'application/xml')
                ->post($this->getBaseUrl().'/invoices');

            $ftaResponse = FtaResponse::fromApiResponse($response->json() ?? []);

            $newStatus = match (true) {
                $ftaResponse->success => FtaStatus::Accepted,
                $ftaResponse->status === 'pending_review' => FtaStatus::PendingReview,
                default => FtaStatus::Rejected,
            };

            $submission->update([
                'status' => $newStatus,
                'reference' => $ftaResponse->submissionId,
                'validation_status' => $ftaResponse->validationStatus,
                'warnings' => $ftaResponse->warnings,
                'errors' => $ftaResponse->errors,
                'accepted_at' => $newStatus === FtaStatus::Accepted ? now() : null,
            ]);

        } catch (\Throwable $e) {
            Log::error('UAE FTA submission failed', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);

            $submission->update([
                'status' => FtaStatus::Failed,
                'last_error' => $e->getMessage(),
                'next_retry_at' => $this->nextRetryAt($submission->retry_count),
            ]);
        }

        return $submission->fresh();
    }

    /**
     * Build DTO from Invoice + Organization Eloquent models.
     * Adapt field mapping to match your actual Invoice model columns.
     */
    public function buildInvoiceData(Invoice $invoice, Organization $organization): FtaInvoiceData
    {
        // Every name below was wrong, and each one failed the same silent way:
        // Eloquent answers null for an attribute a model does not have, and the
        // ?? beside it turned that into an empty string. So the payload carried
        // no supplier TRN, no addresses and no customer name, and the FTA — for
        // which the supplier TRN is mandatory — could only reject it.
        //
        // There is no contact relation. A buyer is recorded on the invoice
        // itself, and buyer_address is cast to an array.
        $buyer = (array) ($invoice->buyer_address ?? []);

        return new FtaInvoiceData(
            invoiceNumber: $invoice->invoice_number,
            invoiceDate: $invoice->issue_date->format('Y-m-d'),
            dueDate: ($invoice->supply_date ?? $invoice->issue_date)->format('Y-m-d'),
            currencyCode: 'AED',
            supplierName: $organization->name,
            supplierTrn: $organization->vat_number ?? '',
            supplierStreet: $organization->street ?? '',
            supplierCity: $organization->city ?? '',
            supplierCountry: 'AE',
            customerName: $invoice->buyer_name ?? '',
            customerTrn: $invoice->buyer_vat_number,
            customerStreet: $buyer['street'] ?? '',
            customerCity: $buyer['city'] ?? '',
            customerCountry: $buyer['country_code'] ?? 'AE',
            lineExtensionAmount: (float) $invoice->subtotal,
            taxExclusiveAmount: (float) $invoice->subtotal,
            taxInclusiveAmount: (float) $invoice->total,
            payableAmount: (float) $invoice->total,
            vatAmount: (float) $invoice->tax_amount,
            vatRate: 0.05,
            lines: $this->mapLines($invoice),
            documentType: '380',
            creditNoteReference: null,
        );
    }

    private function mapLines(Invoice $invoice): array
    {
        return $invoice->lines->map(fn ($line) => [
            // product_name is not a column; description is, and it is required.
            'description' => $line->description ?? '',
            'quantity' => (float) ($line->quantity ?? 1),
            'unit_price' => (float) $line->unit_price,
            'net_amount' => (float) $line->line_total,
            'tax_amount' => (float) ($line->tax_amount ?? 0),
            'unit_code' => 'PCE',
        ])->toArray();
    }

    private function getBaseUrl(): string
    {
        $env = config('fta.environment', 'sandbox');

        return config("fta.endpoints.{$env}");
    }

    private function getApiKey(): string
    {
        return config('fta.api_key', '');
    }

    private function nextRetryAt(int $retryCount): Carbon
    {
        $backoff = config('fta.retry.backoff', [60, 300, 900, 3600, 7200]);
        $seconds = $backoff[$retryCount] ?? 7200;

        return now()->addSeconds($seconds);
    }
}
