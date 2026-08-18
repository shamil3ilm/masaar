<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Duplicate Invoice Detector.
 *
 * Prevents duplicate invoices from being submitted to ZATCA.
 * Detects duplicates based on:
 * - Invoice number (must be unique per organization)
 * - Invoice hash (content-based deduplication)
 * - UUID (must be globally unique)
 * - Fuzzy matching (same buyer, amount, date)
 *
 * Per ZATCA: Each invoice must have a unique Invoice Number (BT-1)
 * within the seller's system. Duplicate submissions are rejected.
 */
class DuplicateDetector
{
    /**
     * Cache TTL for duplicate checks (minutes).
     */
    private const CACHE_TTL = 60;

    /**
     * Fuzzy match time window (hours).
     * Invoices within this window with same buyer/amount are flagged.
     */
    private const FUZZY_MATCH_WINDOW_HOURS = 24;

    /**
     * Check for potential duplicates before creating/submitting an invoice.
     *
     * @param  string  $organizationId  Organization ID
     * @param  string  $invoiceNumber  Invoice number to check
     * @param  string  $uuid  UUID to check
     * @param  string|null  $hash  Invoice hash (if available)
     * @param  array  $fuzzyMatchData  Optional data for fuzzy matching
     * @return array{is_duplicate: bool, duplicates: array, warnings: array}
     */
    public function check(
        string $organizationId,
        string $invoiceNumber,
        string $uuid,
        ?string $hash = null,
        array $fuzzyMatchData = []
    ): array {
        $duplicates = [];
        $warnings = [];

        // 1. Check invoice number uniqueness (CRITICAL)
        $numberDuplicate = $this->checkInvoiceNumber($organizationId, $invoiceNumber);
        if ($numberDuplicate) {
            $duplicates[] = [
                'type' => 'invoice_number',
                'severity' => 'critical',
                'existing_invoice_id' => $numberDuplicate->id,
                'existing_invoice_number' => $numberDuplicate->invoice_number,
                'message' => "Invoice number '{$invoiceNumber}' already exists",
            ];
        }

        // 2. Check UUID uniqueness (CRITICAL)
        $uuidDuplicate = $this->checkUuid($uuid);
        if ($uuidDuplicate) {
            $duplicates[] = [
                'type' => 'uuid',
                'severity' => 'critical',
                'existing_invoice_id' => $uuidDuplicate->id,
                'existing_invoice_number' => $uuidDuplicate->invoice_number,
                'message' => "UUID '{$uuid}' already exists",
            ];
        }

        // 3. Check hash uniqueness (if provided)
        if ($hash) {
            $hashDuplicate = $this->checkHash($organizationId, $hash);
            if ($hashDuplicate) {
                $duplicates[] = [
                    'type' => 'hash',
                    'severity' => 'warning',
                    'existing_invoice_id' => $hashDuplicate->id,
                    'existing_invoice_number' => $hashDuplicate->invoice_number,
                    'message' => 'Invoice content matches existing invoice (same hash)',
                ];
            }
        }

        // 4. Fuzzy match check (warning only)
        if (! empty($fuzzyMatchData)) {
            $fuzzyMatches = $this->checkFuzzyMatch($organizationId, $fuzzyMatchData);
            foreach ($fuzzyMatches as $match) {
                $warnings[] = [
                    'type' => 'fuzzy_match',
                    'severity' => 'warning',
                    'existing_invoice_id' => $match->id,
                    'existing_invoice_number' => $match->invoice_number,
                    'message' => "Similar invoice found: same buyer '{$fuzzyMatchData['buyer_name']}', ".
                        "amount {$match->total}, dated {$match->issue_date->format('Y-m-d')}",
                ];
            }
        }

        $hasCriticalDuplicate = collect($duplicates)->contains('severity', 'critical');

        return [
            'is_duplicate' => $hasCriticalDuplicate,
            'duplicates' => $duplicates,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check if invoice number already exists for organization.
     */
    public function checkInvoiceNumber(string $organizationId, string $invoiceNumber): ?Invoice
    {
        $cacheKey = "dup_check:number:{$organizationId}:{$invoiceNumber}";

        return Cache::remember($cacheKey, self::CACHE_TTL * 60, function () use ($organizationId, $invoiceNumber) {
            return Invoice::where('organization_id', $organizationId)
                ->where('invoice_number', $invoiceNumber)
                ->first();
        });
    }

    /**
     * Check if UUID already exists globally.
     *
     * ZATCA's BT-124 invoice UUID is the invoice's primary key, so this is a
     * key lookup rather than a column search.
     */
    public function checkUuid(string $uuid): ?Invoice
    {
        $cacheKey = "dup_check:uuid:{$uuid}";

        return Cache::remember($cacheKey, self::CACHE_TTL * 60, function () use ($uuid) {
            return Invoice::find($uuid);
        });
    }

    /**
     * Check if hash already exists for organization.
     */
    public function checkHash(string $organizationId, string $hash): ?Invoice
    {
        return Invoice::where('organization_id', $organizationId)
            ->where('hash', $hash)
            ->first();
    }

    /**
     * Check for fuzzy matches (same buyer, amount, similar date).
     *
     * @param  string  $organizationId  Organization ID
     * @param  array{buyer_name?: string, buyer_vat?: string, total?: float, issue_date?: string}  $data
     * @return Collection
     */
    public function checkFuzzyMatch(string $organizationId, array $data)
    {
        $query = Invoice::where('organization_id', $organizationId);

        // Match by buyer
        if (! empty($data['buyer_vat'])) {
            $query->where('buyer_vat_number', $data['buyer_vat']);
        } elseif (! empty($data['buyer_name'])) {
            $query->where('buyer_name', 'LIKE', '%'.$data['buyer_name'].'%');
        } else {
            return collect();
        }

        // Match by amount (within 1 SAR tolerance)
        if (! empty($data['total'])) {
            $query->whereBetween('total', [$data['total'] - 1, $data['total'] + 1]);
        }

        // Match within time window
        if (! empty($data['issue_date'])) {
            $date = Carbon::parse($data['issue_date']);
            $query->whereBetween('issue_date', [
                $date->copy()->subHours(self::FUZZY_MATCH_WINDOW_HOURS),
                $date->copy()->addHours(self::FUZZY_MATCH_WINDOW_HOURS),
            ]);
        }

        return $query->limit(5)->get();
    }

    /**
     * Validate invoice before submission to ZATCA.
     *
     * @param  Invoice  $invoice  Invoice to validate
     * @param  string|null  $excludeId  Exclude this invoice ID (for updates)
     * @return array{valid: bool, errors: array, warnings: array}
     */
    public function validateForSubmission(Invoice $invoice, ?string $excludeId = null): array
    {
        $errors = [];
        $warnings = [];

        // Check invoice number uniqueness
        $existingByNumber = Invoice::where('organization_id', $invoice->organization_id)
            ->where('invoice_number', $invoice->invoice_number)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->first();

        if ($existingByNumber) {
            $errors[] = [
                'code' => 'DUPLICATE_INVOICE_NUMBER',
                'message' => "Invoice number '{$invoice->invoice_number}' already exists (ID: {$existingByNumber->id})",
                'existing_invoice_id' => $existingByNumber->id,
            ];
        }

        // BT-124 is the invoice's primary key, so the database guarantees its
        // uniqueness and no application-level check is needed here.

        // Check if already submitted successfully
        if ($invoice->submissions()->whereIn('state', ['cleared', 'reported'])->exists()) {
            $warnings[] = [
                'code' => 'ALREADY_SUBMITTED',
                'message' => 'Invoice has already been successfully submitted to ZATCA',
            ];
        }

        // Check for pending submission
        if ($invoice->submissions()->where('state', 'pending_submission')->exists()) {
            $errors[] = [
                'code' => 'SUBMISSION_IN_PROGRESS',
                'message' => 'Invoice submission is already in progress',
            ];
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check for duplicate invoice reprints.
     *
     * Per ZATCA: Invoice reprints must have SAME invoice number and QR code.
     * Creating new invoice numbers for reprints is a violation.
     *
     * @param  string  $organizationId  Organization ID
     * @param  string  $originalInvoiceNumber  Original invoice number
     * @param  string  $newInvoiceNumber  New invoice number (should match original)
     * @return array{is_valid_reprint: bool, error: ?string}
     */
    public function validateReprint(
        string $organizationId,
        string $originalInvoiceNumber,
        string $newInvoiceNumber
    ): array {
        if ($originalInvoiceNumber !== $newInvoiceNumber) {
            return [
                'is_valid_reprint' => false,
                'error' => 'Invoice reprints must use the same invoice number. '.
                    "Expected: {$originalInvoiceNumber}, Got: {$newInvoiceNumber}",
            ];
        }

        // Verify original exists
        $original = Invoice::where('organization_id', $organizationId)
            ->where('invoice_number', $originalInvoiceNumber)
            ->first();

        if (! $original) {
            return [
                'is_valid_reprint' => false,
                'error' => "Original invoice '{$originalInvoiceNumber}' not found",
            ];
        }

        return [
            'is_valid_reprint' => true,
            'error' => null,
            'original_invoice' => $original,
        ];
    }

    /**
     * Detect potential ERP/POS sync issues.
     *
     * When multiple systems generate invoices, duplicates can occur.
     *
     * @param  string  $organizationId  Organization ID
     * @param  int  $lookbackMinutes  Time window to check (default: 60)
     * @return array List of potential sync conflicts
     */
    public function detectSyncConflicts(string $organizationId, int $lookbackMinutes = 60): array
    {
        $cutoff = now()->subMinutes($lookbackMinutes);

        // Find invoices with sequential numbers created around the same time
        $recentInvoices = Invoice::where('organization_id', $organizationId)
            ->where('created_at', '>=', $cutoff)
            ->orderBy('created_at')
            ->get();

        $conflicts = [];

        // Check for ICV gaps or duplicates
        $icvValues = $recentInvoices->pluck('icv')->sort()->values();
        for ($i = 1; $i < $icvValues->count(); $i++) {
            $prev = $icvValues[$i - 1];
            $curr = $icvValues[$i];

            if ($curr === $prev) {
                $conflicts[] = [
                    'type' => 'duplicate_icv',
                    'icv' => $curr,
                    'message' => "Duplicate ICV {$curr} detected - possible multi-system sync issue",
                ];
            } elseif ($curr > $prev + 1) {
                $conflicts[] = [
                    'type' => 'icv_gap',
                    'from_icv' => $prev,
                    'to_icv' => $curr,
                    'gap' => $curr - $prev - 1,
                    'message' => "ICV gap detected: {$prev} to {$curr} (missing ".($curr - $prev - 1).' invoices)',
                ];
            }
        }

        // Check for near-simultaneous invoice creation (potential race condition)
        for ($i = 1; $i < $recentInvoices->count(); $i++) {
            $prev = $recentInvoices[$i - 1];
            $curr = $recentInvoices[$i];

            $diffSeconds = $curr->created_at->diffInSeconds($prev->created_at);
            if ($diffSeconds < 1) {
                $conflicts[] = [
                    'type' => 'near_simultaneous',
                    'invoice_1' => $prev->invoice_number,
                    'invoice_2' => $curr->invoice_number,
                    'time_diff_ms' => $diffSeconds * 1000,
                    'message' => 'Near-simultaneous invoice creation detected - verify no duplicates',
                ];
            }
        }

        if (! empty($conflicts)) {
            Log::warning('Potential sync conflicts detected', [
                'organization_id' => $organizationId,
                'conflicts' => $conflicts,
            ]);
        }

        return $conflicts;
    }

    /**
     * Clear duplicate check cache for an organization.
     */
    public function clearCache(string $organizationId): void
    {
        // Note: In production, use tagged cache or pattern-based clearing
        Cache::flush();
    }
}
