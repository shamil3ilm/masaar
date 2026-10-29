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
 * updates and deletes outright on MySQL (migration 1817); the chain is what
 * proves to someone outside the platform that nothing was rewritten
 * (`platform:verify-audit-chain`). The solution must not allow its logs to be
 * modified or deleted (ZATCA E-Invoicing Detailed Guidelines 5.6, prohibited
 * functions, p27).
 *
 * With a chain key (audit.chain_key, never APP_KEY) each new entry and its
 * chain's head also carry a keyed seal (seal()): someone who can write to
 * the database could recompute the plain hashes, but not the seals.
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
     *
     * Inside a caller's transaction the head stays locked until that commits,
     * so every audited write of the organization waits behind it. Take it
     * last: a transaction that holds it and then waits for a device's
     * issuing lock, an invoice or a number series deadlocks with an
     * issuance, which takes those first and the head at the end (the order
     * is listed in IssuingLifecycle::issue).
     */
    public static function append(AuditLog $entry): void
    {
        $chain = self::chainOf($entry->org_id === null ? null : (string) $entry->org_id);

        // Created or locked in one statement, as issuing_locks is. It was an
        // INSERT IGNORE and then a locking read, which deadlocked two entries
        // of one organization written together: on MySQL the ignored
        // duplicate takes a shared lock on the row, both held one, and each
        // waited for the other's to lock it. Reading first is no better: two
        // reads of a chain with no head yet both lock the gap and then both
        // wait to insert into it. An upsert that meets the row locks it
        // exclusively, so the second entry waits for the first instead.
        DB::table('audit_chain_heads')->upsert(
            [['chain' => $chain, 'last_seq' => 0, 'last_hash' => self::GENESIS]],
            ['chain'],
            ['chain'],
        );
        $head = DB::table('audit_chain_heads')->where('chain', $chain)->lockForUpdate()->first();

        $entry->setAttribute('chain', $chain);
        $entry->setAttribute('chain_seq', (int) $head->last_seq + 1);
        $entry->setAttribute('previous_entry_hash', (string) $head->last_hash);
        $entry->setAttribute('entry_hash', self::digest($entry->getAttributes(), (string) $head->last_hash));
        $entry->setAttribute('entry_mac', self::seal((string) $entry->getAttribute('entry_hash')));

        DB::table('audit_chain_heads')->where('chain', $chain)->update([
            'last_seq' => $entry->getAttribute('chain_seq'),
            'last_hash' => $entry->getAttribute('entry_hash'),
            'last_mac' => self::seal(self::headContent($chain, (int) $entry->getAttribute('chain_seq'), (string) $entry->getAttribute('entry_hash'))),
        ]);
    }

    /**
     * The keyed seal of a hash, under the chain key; null with no key.
     * An entry's hash already covers its chain, place and predecessor.
     *
     * @throws \RuntimeException when the chain key is the application key
     */
    public static function seal(string $content, ?string $key = null): ?string
    {
        $key ??= self::keys()[0] ?? null;

        return $key === null ? null : hash_hmac('sha256', $content, $key);
    }

    /** What a head's seal covers: its chain, last place and last hash. */
    public static function headContent(string $chain, int $seq, string $hash): string
    {
        return $chain.'|'.$seq.'|'.$hash;
    }

    /**
     * The chain keys, as bytes: the current one first, then the previous
     * ones, which only verify. Empty with no key.
     *
     * @return list<string>
     *
     * @throws \RuntimeException when one is the application key
     */
    public static function keys(): array
    {
        $configured = array_values(array_filter([(string) config('audit.chain_key'), ...(array) config('audit.chain_previous_keys', [])], fn (string $key): bool => $key !== ''));
        $appKey = (string) config('app.key');

        return array_map(function (string $key) use ($appKey): string {
            if ($appKey !== '' && hash_equals($appKey, $key)) {
                throw new \RuntimeException('AUDIT_CHAIN_KEY must not be the application key: give the audit chain a key of its own (openssl rand -base64 32).');
            }

            return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
        }, $configured);
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
