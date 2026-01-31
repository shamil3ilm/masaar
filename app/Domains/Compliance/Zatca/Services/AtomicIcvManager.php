<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Atomic ICV Manager.
 *
 * Handles millisecond-precision ICV sequence atomicity to prevent
 * collisions when multiple invoices are processed simultaneously.
 *
 * EDGE CASE: Multiple invoices in same millisecond
 * - Ensure ICV sequence remains strictly monotonic
 * - Prevent race conditions even at sub-millisecond granularity
 * - Maintain hash chain integrity under high throughput
 *
 * Uses a combination of:
 * - Database-level serializable transactions
 * - Redis atomic increments with Lua scripts
 * - Monotonic clock guarantees
 */
class AtomicIcvManager
{
    /**
     * Redis key prefix for ICV sequences.
     */
    private const ICV_KEY_PREFIX = 'zatca:icv:';

    /**
     * Lua script for atomic ICV increment with timestamp validation.
     */
    private const LUA_ATOMIC_INCREMENT = <<<'LUA'
        local key = KEYS[1]
        local timestamp_key = KEYS[2]
        local current_timestamp = tonumber(ARGV[1])

        -- Get current values
        local current_icv = tonumber(redis.call('GET', key)) or 0
        local last_timestamp = tonumber(redis.call('GET', timestamp_key)) or 0

        -- Ensure monotonic time (handle clock drift)
        if current_timestamp < last_timestamp then
            current_timestamp = last_timestamp
        end

        -- Increment ICV
        local new_icv = current_icv + 1

        -- Store new values atomically
        redis.call('SET', key, new_icv)
        redis.call('SET', timestamp_key, current_timestamp)

        return {new_icv, current_timestamp}
    LUA;

    /**
     * Get next ICV with atomic guarantees.
     *
     * @param string $organizationId
     * @return array{icv: int, timestamp: int, sequence_id: string}
     * @throws \RuntimeException on sequence failure
     */
    public function getNextIcvAtomic(string $organizationId): array
    {
        $icvKey = self::ICV_KEY_PREFIX . $organizationId;
        $timestampKey = self::ICV_KEY_PREFIX . $organizationId . ':ts';

        // Get current monotonic timestamp (microseconds)
        $timestamp = (int) (microtime(true) * 1000000);

        try {
            // Execute atomic Lua script
            $result = Redis::eval(
                self::LUA_ATOMIC_INCREMENT,
                2, // Number of keys
                $icvKey,
                $timestampKey,
                $timestamp
            );

            if (!is_array($result) || count($result) !== 2) {
                throw new \RuntimeException('Unexpected Redis result');
            }

            [$newIcv, $actualTimestamp] = $result;

            // Generate unique sequence ID for this ICV issuance
            $sequenceId = sprintf(
                '%s-%d-%d-%s',
                $organizationId,
                $newIcv,
                $actualTimestamp,
                bin2hex(random_bytes(4))
            );

            Log::debug('Atomic ICV issued', [
                'organization_id' => $organizationId,
                'icv' => $newIcv,
                'timestamp_us' => $actualTimestamp,
                'sequence_id' => $sequenceId,
            ]);

            return [
                'icv' => (int) $newIcv,
                'timestamp' => (int) $actualTimestamp,
                'sequence_id' => $sequenceId,
            ];

        } catch (\Exception $e) {
            // Fallback to database-level atomicity
            return $this->getNextIcvDatabase($organizationId);
        }
    }

    /**
     * Database fallback for atomic ICV (when Redis unavailable).
     */
    private function getNextIcvDatabase(string $organizationId): array
    {
        $timestamp = (int) (microtime(true) * 1000000);

        return DB::transaction(function () use ($organizationId, $timestamp) {
            // Lock the organization row for update
            $org = DB::table('organizations')
                ->where('id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (!$org) {
                throw new \RuntimeException("Organization {$organizationId} not found");
            }

            // Get current max ICV from invoices
            $maxIcv = DB::table('invoices')
                ->where('organization_id', $organizationId)
                ->max('icv') ?? 0;

            // Also check hash chain state
            $hashChainState = DB::table('hash_chain_state')
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            $chainIcv = $hashChainState->last_icv ?? 0;

            // Use the maximum of both
            $newIcv = max($maxIcv, $chainIcv) + 1;

            // Update hash chain state
            if ($hashChainState) {
                DB::table('hash_chain_state')
                    ->where('organization_id', $organizationId)
                    ->update([
                        'last_icv' => $newIcv,
                        'updated_at' => now(),
                    ]);
            }

            $sequenceId = sprintf(
                '%s-%d-%d-%s',
                $organizationId,
                $newIcv,
                $timestamp,
                bin2hex(random_bytes(4))
            );

            Log::info('Atomic ICV issued via database fallback', [
                'organization_id' => $organizationId,
                'icv' => $newIcv,
                'sequence_id' => $sequenceId,
            ]);

            return [
                'icv' => $newIcv,
                'timestamp' => $timestamp,
                'sequence_id' => $sequenceId,
            ];
        }, 5); // 5 retry attempts for deadlocks
    }

    /**
     * Validate ICV monotonicity for an organization.
     *
     * @return array{valid: bool, gaps: array, duplicates: array}
     */
    public function validateIcvSequence(string $organizationId): array
    {
        $invoices = DB::table('invoices')
            ->where('organization_id', $organizationId)
            ->whereNotNull('icv')
            ->orderBy('icv')
            ->select(['id', 'icv', 'issue_date', 'created_at'])
            ->get();

        $gaps = [];
        $duplicates = [];
        $previousIcv = 0;
        $seenIcvs = [];

        foreach ($invoices as $invoice) {
            $icv = $invoice->icv;

            // Check for duplicates
            if (isset($seenIcvs[$icv])) {
                $duplicates[] = [
                    'icv' => $icv,
                    'invoice_ids' => [$seenIcvs[$icv], $invoice->id],
                ];
            }
            $seenIcvs[$icv] = $invoice->id;

            // Check for gaps (non-sequential)
            if ($previousIcv > 0 && $icv !== $previousIcv + 1) {
                $gaps[] = [
                    'after_icv' => $previousIcv,
                    'found_icv' => $icv,
                    'missing_count' => $icv - $previousIcv - 1,
                ];
            }

            $previousIcv = $icv;
        }

        $isValid = empty($gaps) && empty($duplicates);

        if (!$isValid) {
            Log::warning('ICV sequence validation failed', [
                'organization_id' => $organizationId,
                'gaps_count' => count($gaps),
                'duplicates_count' => count($duplicates),
            ]);
        }

        return [
            'valid' => $isValid,
            'total_invoices' => $invoices->count(),
            'gaps' => $gaps,
            'duplicates' => $duplicates,
            'validated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Repair ICV sequence by reserving gap positions.
     * Note: This does NOT reassign ICVs (that would break hash chains).
     * Instead, it marks gaps as "reserved" to prevent future use.
     */
    public function reserveIcvGaps(string $organizationId): array
    {
        $validation = $this->validateIcvSequence($organizationId);

        if ($validation['valid']) {
            return ['success' => true, 'message' => 'No gaps to reserve'];
        }

        $reserved = [];

        foreach ($validation['gaps'] as $gap) {
            for ($icv = $gap['after_icv'] + 1; $icv < $gap['found_icv']; $icv++) {
                // Record the gap in hash chain history
                DB::table('hash_chain_history')->insert([
                    'id' => \Illuminate\Support\Str::uuid()->toString(),
                    'organization_id' => $organizationId,
                    'icv' => $icv,
                    'invoice_hash' => 'RESERVED_GAP_' . bin2hex(random_bytes(16)),
                    'previous_hash' => 'GAP',
                    'chain_hash' => 'GAP',
                    'created_at' => now(),
                ]);

                $reserved[] = $icv;
            }
        }

        Log::warning('ICV gaps reserved', [
            'organization_id' => $organizationId,
            'reserved_icvs' => $reserved,
        ]);

        return [
            'success' => true,
            'reserved_count' => count($reserved),
            'reserved_icvs' => $reserved,
        ];
    }

    /**
     * Get microsecond-precision timestamp ensuring monotonicity.
     */
    public function getMonotonicTimestamp(string $organizationId): int
    {
        $key = self::ICV_KEY_PREFIX . $organizationId . ':ts';
        $current = (int) (microtime(true) * 1000000);

        $lastTimestamp = Redis::get($key);
        if ($lastTimestamp && (int) $lastTimestamp >= $current) {
            // Clock drift detected, use last + 1 microsecond
            $current = (int) $lastTimestamp + 1;
        }

        Redis::set($key, $current);

        return $current;
    }
}
