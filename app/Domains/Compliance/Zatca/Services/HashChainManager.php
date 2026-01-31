<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\Enums\ErrorCode;
use App\Domains\Compliance\Zatca\Exceptions\ZatcaException;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hash Chain Manager - Ensures hash-chain continuity.
 *
 * CRITICAL: This service prevents the most dangerous compliance failure -
 * a broken hash chain. ZATCA doesn't care why it broke; broken chain = non-compliance.
 *
 * This addresses:
 * - Parallel processing breaking sequence
 * - Out-of-order retries
 * - Offline queue replay
 * - Concurrent submissions from multiple workers
 *
 * Pattern: Single-writer per sequence with atomic hash persistence.
 */
class HashChainManager
{
    /**
     * Lock prefix for sequence locks.
     */
    private const LOCK_PREFIX = 'zatca:hash_chain_lock:';

    /**
     * Lock timeout in seconds.
     */
    private const LOCK_TIMEOUT = 30;

    /**
     * Default PIH for first invoice (base64 SHA256 of zeros).
     */
    private const DEFAULT_FIRST_PIH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    /**
     * Acquire exclusive lock for hash chain operations.
     *
     * @param string $organizationId Organization ID
     * @param int $timeout Lock timeout in seconds
     * @return string Lock token for release
     * @throws ZatcaException If lock cannot be acquired
     */
    public function acquireLock(string $organizationId, int $timeout = self::LOCK_TIMEOUT): string
    {
        $lockKey = self::LOCK_PREFIX . $organizationId;
        $lockToken = bin2hex(random_bytes(16));

        $acquired = Cache::lock($lockKey, $timeout)->get(function () use ($lockToken) {
            return $lockToken;
        });

        if ($acquired !== $lockToken) {
            // Check if there's an existing lock
            $existingLock = Cache::get($lockKey . ':info');

            throw new ZatcaException(
                'Cannot acquire hash chain lock - another submission is in progress',
                ErrorCode::RATE_CONCURRENT_LIMIT,
                [
                    'organization_id' => $organizationId,
                    'existing_lock' => $existingLock,
                    'retry_after' => $timeout,
                ]
            );
        }

        // Store lock metadata for debugging
        Cache::put($lockKey . ':info', [
            'token' => $lockToken,
            'acquired_at' => now()->toIso8601String(),
            'expires_at' => now()->addSeconds($timeout)->toIso8601String(),
            'pid' => getmypid(),
        ], $timeout);

        Log::debug('Hash chain lock acquired', [
            'organization_id' => $organizationId,
            'lock_token' => substr($lockToken, 0, 8) . '...',
        ]);

        return $lockToken;
    }

    /**
     * Release hash chain lock.
     */
    public function releaseLock(string $organizationId, string $lockToken): void
    {
        $lockKey = self::LOCK_PREFIX . $organizationId;

        // Verify token before release
        $info = Cache::get($lockKey . ':info');
        if ($info && $info['token'] === $lockToken) {
            Cache::forget($lockKey);
            Cache::forget($lockKey . ':info');

            Log::debug('Hash chain lock released', [
                'organization_id' => $organizationId,
            ]);
        }
    }

    /**
     * Execute callback with exclusive hash chain lock.
     *
     * @template T
     * @param string $organizationId
     * @param callable(): T $callback
     * @return T
     * @throws ZatcaException
     */
    public function withLock(string $organizationId, callable $callback): mixed
    {
        $lockToken = $this->acquireLock($organizationId);

        try {
            return $callback();
        } finally {
            $this->releaseLock($organizationId, $lockToken);
        }
    }

    /**
     * Get the last cleared invoice hash for an organization.
     * This is the PIH (Previous Invoice Hash) for the next invoice.
     *
     * @param string $organizationId
     * @return array{hash: string, icv: int, invoice_id: ?string, certificate_id: ?string}
     */
    public function getLastClearedHash(string $organizationId): array
    {
        // Get from database with lock to ensure consistency
        $lastCleared = DB::table('hash_chain_state')
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->first();

        if (!$lastCleared) {
            // First invoice - use default PIH
            return [
                'hash' => self::DEFAULT_FIRST_PIH,
                'icv' => 0,
                'invoice_id' => null,
                'certificate_id' => null,
                'is_first' => true,
            ];
        }

        return [
            'hash' => $lastCleared->last_hash,
            'icv' => $lastCleared->last_icv,
            'invoice_id' => $lastCleared->last_invoice_id,
            'certificate_id' => $lastCleared->certificate_id,
            'is_first' => false,
        ];
    }

    /**
     * Update the hash chain state after successful clearance.
     * MUST be called within a transaction with the lock held.
     *
     * @param string $organizationId
     * @param string $invoiceId
     * @param string $invoiceHash
     * @param int $icv
     * @param string $certificateId Certificate used for signing
     * @throws ZatcaException
     */
    public function updateChainState(
        string $organizationId,
        string $invoiceId,
        string $invoiceHash,
        int $icv,
        string $certificateId
    ): void {
        // Verify this is the expected next ICV
        $current = $this->getLastClearedHash($organizationId);
        $expectedIcv = $current['icv'] + 1;

        if ($icv !== $expectedIcv) {
            throw new ZatcaException(
                "ICV sequence violation: expected {$expectedIcv}, got {$icv}",
                ErrorCode::ZATCA_BUSINESS_RULE_VIOLATION,
                [
                    'expected_icv' => $expectedIcv,
                    'actual_icv' => $icv,
                    'organization_id' => $organizationId,
                ]
            );
        }

        // Check for certificate transition
        $certificateTransition = null;
        if ($current['certificate_id'] && $current['certificate_id'] !== $certificateId) {
            $certificateTransition = [
                'from' => $current['certificate_id'],
                'to' => $certificateId,
                'at_icv' => $icv,
                'at' => now()->toIso8601String(),
            ];

            Log::warning('Certificate transition detected in hash chain', $certificateTransition);
        }

        // Atomic update/insert
        DB::table('hash_chain_state')->updateOrInsert(
            ['organization_id' => $organizationId],
            [
                'last_hash' => $invoiceHash,
                'last_icv' => $icv,
                'last_invoice_id' => $invoiceId,
                'certificate_id' => $certificateId,
                'certificate_transition' => $certificateTransition ? json_encode($certificateTransition) : null,
                'updated_at' => now(),
            ]
        );

        // Also log to chain history for audit
        DB::table('hash_chain_history')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'organization_id' => $organizationId,
            'invoice_id' => $invoiceId,
            'invoice_hash' => $invoiceHash,
            'previous_hash' => $current['hash'],
            'icv' => $icv,
            'certificate_id' => $certificateId,
            'certificate_transition' => $certificateTransition ? json_encode($certificateTransition) : null,
            'created_at' => now(),
        ]);

        Log::info('Hash chain state updated', [
            'organization_id' => $organizationId,
            'invoice_id' => $invoiceId,
            'icv' => $icv,
            'has_certificate_transition' => $certificateTransition !== null,
        ]);
    }

    /**
     * Verify hash chain integrity for an organization.
     *
     * @param string $organizationId
     * @param int $limit Number of recent entries to verify
     * @return array{valid: bool, errors: array, verified_count: int}
     */
    public function verifyChainIntegrity(string $organizationId, int $limit = 100): array
    {
        $history = DB::table('hash_chain_history')
            ->where('organization_id', $organizationId)
            ->orderBy('icv', 'asc')
            ->limit($limit)
            ->get();

        $errors = [];
        $previousHash = self::DEFAULT_FIRST_PIH;
        $previousIcv = 0;

        foreach ($history as $entry) {
            // Verify ICV sequence
            if ($entry->icv !== $previousIcv + 1) {
                $errors[] = [
                    'type' => 'icv_gap',
                    'expected' => $previousIcv + 1,
                    'actual' => $entry->icv,
                    'invoice_id' => $entry->invoice_id,
                ];
            }

            // Verify hash chain
            if ($entry->previous_hash !== $previousHash) {
                $errors[] = [
                    'type' => 'hash_chain_break',
                    'expected_previous' => substr($previousHash, 0, 16) . '...',
                    'actual_previous' => substr($entry->previous_hash, 0, 16) . '...',
                    'icv' => $entry->icv,
                    'invoice_id' => $entry->invoice_id,
                ];
            }

            $previousHash = $entry->invoice_hash;
            $previousIcv = $entry->icv;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'verified_count' => count($history),
            'last_icv' => $previousIcv,
        ];
    }

    /**
     * Get certificate transition history for audit.
     *
     * @param string $organizationId
     * @return array
     */
    public function getCertificateTransitions(string $organizationId): array
    {
        return DB::table('hash_chain_history')
            ->where('organization_id', $organizationId)
            ->whereNotNull('certificate_transition')
            ->orderBy('icv', 'asc')
            ->get()
            ->map(fn($entry) => [
                'icv' => $entry->icv,
                'invoice_id' => $entry->invoice_id,
                'transition' => json_decode($entry->certificate_transition, true),
                'at' => $entry->created_at,
            ])
            ->toArray();
    }

    /**
     * Query invoices by certificate for audit.
     * "Show me every invoice signed with a certificate that expired in March 2024."
     *
     * @param string $organizationId
     * @param string $certificateId
     * @return array
     */
    public function getInvoicesByCertificate(string $organizationId, string $certificateId): array
    {
        return DB::table('hash_chain_history')
            ->where('organization_id', $organizationId)
            ->where('certificate_id', $certificateId)
            ->orderBy('icv', 'asc')
            ->get()
            ->toArray();
    }
}
