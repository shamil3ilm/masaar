<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * The audit log as a hash chain, one per organization and one for the
 * platform's own entries.
 *
 * Each entry carries its place in its chain (chain_seq), the hash of the
 * entry before it (previous_entry_hash) and a SHA-256 of its own content with that
 * previous hash (entry_hash). Changing, removing or reordering an entry
 * breaks every hash after it, and audit_chain_heads keeps the last entry of
 * each chain, so cutting entries off the end shows too. The database refuses
 * updates and deletes outright on MySQL (migration 1811); the chain is what
 * proves to someone outside the platform that nothing was rewritten
 * (`platform:verify-audit-chain`). The solution must not allow its logs to be
 * modified or deleted (ZATCA E-Invoicing Detailed Guidelines 5.6, prohibited
 * functions, p27).
 */
final class AuditChain
{
    /** The previous hash of a chain's first entry. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** The chain of entries no organization owns. */
    public const PLATFORM = 'platform';

    /** What the hash covers, in this order. */
    private const FIELDS = [
        'id', 'chain', 'chain_seq', 'org_id', 'user_id', 'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'ip_address', 'user_agent', 'metadata', 'request_id', 'created_at',
    ];

    private const JSON_FIELDS = ['old_values', 'new_values', 'metadata'];

    public static function chainOf(?string $organizationId): string
    {
        return $organizationId ?? self::PLATFORM;
    }

    /**
     * Places a new entry at the end of its chain. Called while the entry is
     * being inserted, inside the transaction AuditLog opens for it: the
     * chain's head row is locked until the entry is written, so two entries
     * never claim the same place.
     */
    public static function append(AuditLog $entry): void
    {
        $chain = self::chainOf($entry->org_id === null ? null : (string) $entry->org_id);

        DB::table('audit_chain_heads')->insertOrIgnore(['chain' => $chain, 'last_seq' => 0, 'last_hash' => self::GENESIS]);
        $head = DB::table('audit_chain_heads')->where('chain', $chain)->lockForUpdate()->first();

        $entry->setAttribute('chain', $chain);
        $entry->setAttribute('chain_seq', (int) $head->last_seq + 1);
        $entry->setAttribute('previous_entry_hash', (string) $head->last_hash);
        $entry->setAttribute('entry_hash', self::digest($entry->getAttributes(), (string) $head->last_hash));

        DB::table('audit_chain_heads')->where('chain', $chain)->update([
            'last_seq' => $entry->getAttribute('chain_seq'),
            'last_hash' => $entry->getAttribute('entry_hash'),
        ]);
    }

    /**
     * The hash of one entry, from its stored columns as the database returns
     * them, or as the model is about to write them. JSON columns are compared
     * by value with their keys sorted, since MySQL reorders an object's keys.
     *
     * @param  array<string, mixed>  $row
     */
    public static function digest(array $row, string $previousHash): string
    {
        $content = [];

        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;

            if (in_array($field, self::JSON_FIELDS, true)) {
                $value = $value === null ? null : self::canonical(is_string($value) ? json_decode($value, true) : $value);
            } elseif ($value !== null) {
                $value = (string) $value;
            }

            $content[$field] = $value;
        }

        $content['previous_entry_hash'] = $previousHash;

        return hash('sha256', (string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
