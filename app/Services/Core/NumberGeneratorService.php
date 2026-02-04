<?php

declare(strict_types=1);

namespace App\Services\Core;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Service for generating sequential document numbers.
 *
 * Supports prefixes and format patterns like:
 * - INV-2024-00001
 * - SO-00001
 * - PO-2024-01-00001
 */
class NumberGeneratorService
{
    /**
     * Generate a new sequential number.
     */
    public function generate(
        string $prefix,
        ?string $format = null,
        ?int $organizationId = null
    ): string {
        $organizationId = $organizationId ?? auth()->user()?->organization_id;
        $format = $format ?? config('erp.number_format', '{prefix}-{year}-{number}');

        $key = $this->getSequenceKey($prefix, $organizationId);

        // Use database lock for atomicity
        return DB::transaction(function () use ($prefix, $format, $organizationId, $key) {
            // Get and increment sequence
            $sequence = $this->getNextSequence($key, $organizationId);

            // Build the number
            return $this->formatNumber($prefix, $sequence, $format);
        });
    }

    /**
     * Generate number with custom pattern.
     */
    public function generateWithPattern(
        string $prefix,
        string $pattern,
        ?int $organizationId = null
    ): string {
        return $this->generate($prefix, $pattern, $organizationId);
    }

    /**
     * Preview what the next number will be (without incrementing).
     */
    public function preview(
        string $prefix,
        ?string $format = null,
        ?int $organizationId = null
    ): string {
        $organizationId = $organizationId ?? auth()->user()?->organization_id;
        $format = $format ?? config('erp.number_format', '{prefix}-{year}-{number}');

        $key = $this->getSequenceKey($prefix, $organizationId);
        $sequence = $this->getCurrentSequence($key, $organizationId) + 1;

        return $this->formatNumber($prefix, $sequence, $format);
    }

    /**
     * Reset sequence to a specific value.
     */
    public function reset(string $prefix, int $value = 0, ?int $organizationId = null): void
    {
        $organizationId = $organizationId ?? auth()->user()?->organization_id;
        $key = $this->getSequenceKey($prefix, $organizationId);

        DB::table('number_sequences')->updateOrInsert(
            [
                'organization_id' => $organizationId,
                'sequence_key' => $key,
            ],
            [
                'current_value' => $value,
                'updated_at' => now(),
            ]
        );

        Cache::forget("sequence:{$key}");
    }

    /**
     * Get the sequence key for a prefix/org combination.
     */
    protected function getSequenceKey(string $prefix, ?int $organizationId): string
    {
        $year = date('Y');
        return "{$organizationId}:{$prefix}:{$year}";
    }

    /**
     * Get and increment the next sequence number.
     */
    protected function getNextSequence(string $key, ?int $organizationId): int
    {
        // Lock the row for update
        $result = DB::table('number_sequences')
            ->where('sequence_key', $key)
            ->lockForUpdate()
            ->first();

        if ($result) {
            $newValue = $result->current_value + 1;

            DB::table('number_sequences')
                ->where('sequence_key', $key)
                ->update([
                    'current_value' => $newValue,
                    'updated_at' => now(),
                ]);
        } else {
            $newValue = 1;

            DB::table('number_sequences')->insert([
                'organization_id' => $organizationId,
                'sequence_key' => $key,
                'current_value' => $newValue,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::put("sequence:{$key}", $newValue, 3600);

        return $newValue;
    }

    /**
     * Get current sequence without incrementing.
     */
    protected function getCurrentSequence(string $key, ?int $organizationId): int
    {
        return Cache::remember("sequence:{$key}", 300, function () use ($key) {
            return DB::table('number_sequences')
                ->where('sequence_key', $key)
                ->value('current_value') ?? 0;
        });
    }

    /**
     * Format the number according to the pattern.
     */
    protected function formatNumber(string $prefix, int $sequence, string $format): string
    {
        $replacements = [
            '{prefix}' => $prefix,
            '{number}' => str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            '{number:4}' => str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            '{number:6}' => str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            '{year}' => date('Y'),
            '{year:2}' => date('y'),
            '{month}' => date('m'),
            '{day}' => date('d'),
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $format
        );
    }
}
