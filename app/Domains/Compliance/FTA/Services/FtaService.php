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
            'invoice_id'      => $invoice->id,
            'organization_id' => $organization->id,
            'status'          => FtaStatus::Queued,
            'document_type'   => $data->documentType,
            'invoice_xml'     => $xml,
            'max_retries'     => config('fta.retry.max_attempts', 5),
            'retry_count'     => 0,
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
            'status'      => FtaStatus::Queued,
            'retry_count' => $submission->retry_count + 1,
        ]);

        return $this->dispatch($submission);
    }

    /**
     * Check submission status from FTA (for pending_review submissions).
     */
    public function checkStatus(FtaSubmission $submission): FtaSubmission
    {
        if ($submission->status !== FtaStatus::PendingReview || $submission->fta_submission_id === null) {
            return $submission;
        }

        try {
            $response = Http::withToken($this->getApiKey())
                ->timeout(config('fta.timeout', 30))
                ->get($this->getBaseUrl() . "/submissions/{$submission->fta_submission_id}/status");

            if ($response->successful()) {
                $ftaStatus = $response->json('status');

                $newStatus = match ($ftaStatus) {
                    'accepted' => FtaStatus::Accepted,
                    'rejected' => FtaStatus::Rejected,
                    default    => FtaStatus::PendingReview,
                };

                $submission->update([
                    'status'          => $newStatus,
                    'fta_errors'      => $response->json('errors', []),
                    'accepted_at'     => $newStatus === FtaStatus::Accepted ? now() : null,
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
                ->post($this->getBaseUrl() . '/invoices');

            $ftaResponse = FtaResponse::fromApiResponse($response->json() ?? []);

            $newStatus = match (true) {
                $ftaResponse->success                   => FtaStatus::Accepted,
                $ftaResponse->status === 'pending_review' => FtaStatus::PendingReview,
                default                                 => FtaStatus::Rejected,
            };

            $submission->update([
                'status'               => $newStatus,
                'fta_submission_id'    => $ftaResponse->submissionId,
                'fta_validation_status' => $ftaResponse->validationStatus,
                'fta_warnings'         => $ftaResponse->warnings,
                'fta_errors'           => $ftaResponse->errors,
                'accepted_at'          => $newStatus === FtaStatus::Accepted ? now() : null,
            ]);

        } catch (\Throwable $e) {
            Log::error('UAE FTA submission failed', [
                'submission_id' => $submission->id,
                'error'         => $e->getMessage(),
            ]);

            $submission->update([
                'status'             => FtaStatus::Failed,
                'last_error_message' => $e->getMessage(),
                'next_retry_at'      => $this->nextRetryAt($submission->retry_count),
            ]);
        }

        return $submission->fresh();
    }

    /**
     * Build DTO from Invoice + Organization Eloquent models.
     * Adapt field mapping to match your actual Invoice model columns.
     */
    private function buildInvoiceData(Invoice $invoice, Organization $organization): FtaInvoiceData
    {
        return new FtaInvoiceData(
            invoiceNumber:        $invoice->invoice_number,
            invoiceDate:          $invoice->issue_date->format('Y-m-d'),
            dueDate:              ($invoice->due_date ?? $invoice->issue_date)->format('Y-m-d'),
            currencyCode:         'AED',
            supplierName:         $organization->name,
            supplierTrn:          $organization->tax_registration_number ?? '',
            supplierStreet:       $organization->address ?? '',
            supplierCity:         $organization->city ?? '',
            supplierCountry:      'AE',
            customerName:         $invoice->contact->name ?? '',
            customerTrn:          $invoice->contact->tax_number ?? null,
            customerStreet:       $invoice->contact->address ?? '',
            customerCity:         $invoice->contact->city ?? '',
            customerCountry:      $invoice->contact->country_code ?? 'AE',
            lineExtensionAmount:  (float) $invoice->subtotal,
            taxExclusiveAmount:   (float) $invoice->subtotal,
            taxInclusiveAmount:   (float) $invoice->total,
            payableAmount:        (float) $invoice->total,
            vatAmount:            (float) $invoice->tax_amount,
            vatRate:              0.05,
            lines:                $this->mapLines($invoice),
            documentType:         '380',
            creditNoteReference:  null,
        );
    }

    private function mapLines(Invoice $invoice): array
    {
        return $invoice->lines->map(fn ($line) => [
            'description' => $line->description ?? $line->product_name ?? '',
            'quantity'    => (float) ($line->quantity ?? 1),
            'unit_price'  => (float) $line->unit_price,
            'net_amount'  => (float) $line->line_total,
            'tax_amount'  => (float) ($line->tax_amount ?? 0),
            'unit_code'   => 'PCE',
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

    private function nextRetryAt(int $retryCount): \Carbon\Carbon
    {
        $backoff = config('fta.retry.backoff', [60, 300, 900, 3600, 7200]);
        $seconds = $backoff[$retryCount] ?? 7200;
        return now()->addSeconds($seconds);
    }
}
