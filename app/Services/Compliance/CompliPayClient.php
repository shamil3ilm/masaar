<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Sales\Invoice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for CompliPay - Unified Compliance Gateway.
 *
 * CompliPay handles all government compliance submissions:
 * - ZATCA (Saudi Arabia)
 * - FTA (UAE)
 * - GTA (Qatar)
 * - OTA (Oman)
 * - NBR (Bahrain)
 * - Kuwait Tax Authority
 * - GST/E-way Bill (India)
 */
class CompliPayClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private bool $enabled;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('complipay.url', ''), '/');
        $this->apiKey = config('complipay.api_key', '');
        $this->timeout = (int) config('complipay.timeout', 30);
        $this->enabled = (bool) config('complipay.enabled', true);
    }

    /**
     * Submit an invoice for compliance processing.
     */
    public function submitInvoice(Invoice $invoice): ComplianceResult
    {
        if (!$this->enabled) {
            return new ComplianceResult([
                'status' => 'not_applicable',
                'message' => 'Compliance integration disabled',
            ]);
        }

        try {
            $payload = $this->transformInvoice($invoice);

            $response = $this->client()
                ->post('/invoices/submit', $payload);

            if ($response->failed()) {
                Log::error('CompliPay submission failed', [
                    'invoice_id' => $invoice->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return new ComplianceResult([
                    'status' => 'rejected',
                    'message' => $response->json('message', 'Submission failed'),
                    'errors' => $response->json('errors', []),
                ]);
            }

            $data = $response->json();

            Log::info('CompliPay submission successful', [
                'invoice_id' => $invoice->id,
                'compliance_uuid' => $data['uuid'] ?? null,
            ]);

            return new ComplianceResult($data);

        } catch (\Exception $e) {
            Log::error('CompliPay submission exception', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return new ComplianceResult([
                'status' => 'rejected',
                'message' => 'Connection error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Get compliance status for an invoice.
     */
    public function getStatus(string $complianceUuid): ComplianceResult
    {
        if (!$this->enabled) {
            return new ComplianceResult(['status' => 'not_applicable']);
        }

        try {
            $response = $this->client()
                ->get("/invoices/{$complianceUuid}/status");

            if ($response->failed()) {
                return new ComplianceResult([
                    'status' => 'error',
                    'message' => 'Failed to retrieve status',
                ]);
            }

            return new ComplianceResult($response->json());

        } catch (\Exception $e) {
            return new ComplianceResult([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel/void a submitted invoice.
     */
    public function cancelInvoice(string $complianceUuid, string $reason): ComplianceResult
    {
        if (!$this->enabled) {
            return new ComplianceResult(['status' => 'not_applicable']);
        }

        try {
            $response = $this->client()
                ->post("/invoices/{$complianceUuid}/cancel", [
                    'reason' => $reason,
                ]);

            if ($response->failed()) {
                return new ComplianceResult([
                    'status' => 'error',
                    'message' => 'Cancellation failed: ' . $response->json('message'),
                ]);
            }

            return new ComplianceResult($response->json());

        } catch (\Exception $e) {
            return new ComplianceResult([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Submit a credit note.
     */
    public function submitCreditNote(Invoice $creditNote): ComplianceResult
    {
        if (!$creditNote->isCreditNote()) {
            throw new \InvalidArgumentException('Invoice is not a credit note.');
        }

        return $this->submitInvoice($creditNote);
    }

    /**
     * Report invoice (for Phase 2 ZATCA reporting).
     */
    public function reportInvoice(Invoice $invoice): ComplianceResult
    {
        if (!$this->enabled || !$invoice->compliance_uuid) {
            return new ComplianceResult(['status' => 'not_applicable']);
        }

        try {
            $response = $this->client()
                ->post("/invoices/{$invoice->compliance_uuid}/report");

            return new ComplianceResult($response->json());

        } catch (\Exception $e) {
            return new ComplianceResult([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate invoice data without submitting.
     */
    public function validate(Invoice $invoice): ComplianceResult
    {
        if (!$this->enabled) {
            return new ComplianceResult(['status' => 'valid']);
        }

        try {
            $payload = $this->transformInvoice($invoice);

            $response = $this->client()
                ->post('/invoices/validate', $payload);

            return new ComplianceResult($response->json());

        } catch (\Exception $e) {
            return new ComplianceResult([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get QR code for invoice.
     */
    public function getQrCode(string $complianceUuid): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            $response = $this->client()
                ->get("/invoices/{$complianceUuid}/qr-code");

            if ($response->successful()) {
                return $response->json('qr_code');
            }

            return null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Register a new device/EGS (for ZATCA).
     */
    public function registerDevice(array $deviceData): ComplianceResult
    {
        if (!$this->enabled) {
            return new ComplianceResult(['status' => 'not_applicable']);
        }

        try {
            $response = $this->client()
                ->post('/devices/register', $deviceData);

            return new ComplianceResult($response->json());

        } catch (\Exception $e) {
            return new ComplianceResult([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get HTTP client with authentication.
     */
    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout($this->timeout);
    }

    /**
     * Transform invoice to CompliPay format.
     */
    protected function transformInvoice(Invoice $invoice): array
    {
        $organization = $invoice->organization;
        $customer = $invoice->customer;

        return [
            'country_code' => $organization->country_code,
            'invoice_number' => $invoice->invoice_number,
            'invoice_type' => $this->mapInvoiceType($invoice->invoice_type),
            'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'currency' => $invoice->currency_code,

            'seller' => [
                'name' => $organization->legal_name ?? $organization->name,
                'tax_number' => $organization->tax_number,
                'address' => [
                    'street' => $organization->address_line_1,
                    'city' => $organization->city,
                    'state' => $organization->state,
                    'postal_code' => $organization->postal_code,
                    'country' => $organization->country_code,
                ],
            ],

            'buyer' => [
                'name' => $invoice->customer_name,
                'tax_number' => $invoice->customer_tax_number,
                'email' => $invoice->customer_email,
                'address' => $invoice->billing_address,
            ],

            'lines' => $invoice->lines->map(fn($line) => [
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'discount' => (float) $line->discount_amount,
                'tax_category' => $line->tax_code ?? 'S',
                'tax_rate' => (float) $line->tax_rate,
                'tax_amount' => (float) $line->tax_amount,
                'subtotal' => (float) $line->subtotal,
                'total' => (float) $line->total,
                'hsn_code' => $line->hsn_code,
            ])->toArray(),

            'totals' => [
                'subtotal' => (float) $invoice->subtotal,
                'discount' => (float) $invoice->discount_amount,
                'tax' => (float) $invoice->tax_amount,
                'total' => (float) $invoice->total,
            ],

            // GST specific fields
            'gst' => $organization->tax_scheme === 'GST' ? [
                'place_of_supply' => $invoice->place_of_supply,
                'is_reverse_charge' => $invoice->is_reverse_charge,
                'cgst' => $invoice->lines->sum('cgst_amount'),
                'sgst' => $invoice->lines->sum('sgst_amount'),
                'igst' => $invoice->lines->sum('igst_amount'),
            ] : null,

            // Reference to original invoice (for credit notes)
            'reference_invoice' => $invoice->original_invoice_id ? [
                'number' => $invoice->originalInvoice?->invoice_number,
                'uuid' => $invoice->originalInvoice?->compliance_uuid,
            ] : null,

            'notes' => $invoice->notes,
        ];
    }

    /**
     * Map invoice type to CompliPay format.
     */
    protected function mapInvoiceType(string $type): string
    {
        return match ($type) {
            Invoice::TYPE_STANDARD => 'standard',
            Invoice::TYPE_SIMPLIFIED => 'simplified',
            Invoice::TYPE_CREDIT_NOTE => 'credit_note',
            Invoice::TYPE_DEBIT_NOTE => 'debit_note',
            default => 'standard',
        };
    }
}

/**
 * Compliance submission result.
 */
class ComplianceResult
{
    public string $status;
    public ?string $uuid;
    public ?string $hash;
    public ?string $qrCode;
    public ?string $message;
    public array $errors;
    public array $response;

    public function __construct(array $data)
    {
        $this->status = $data['status'] ?? 'unknown';
        $this->uuid = $data['uuid'] ?? $data['compliance_uuid'] ?? null;
        $this->hash = $data['hash'] ?? $data['invoice_hash'] ?? null;
        $this->qrCode = $data['qr_code'] ?? $data['qrCode'] ?? null;
        $this->message = $data['message'] ?? null;
        $this->errors = $data['errors'] ?? [];
        $this->response = $data;
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, ['submitted', 'cleared', 'reported', 'valid'], true);
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
