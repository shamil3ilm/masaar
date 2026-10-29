<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Audit\Services\AuditChain;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Walks each audit chain (AuditChain) and reports any entry that was changed,
 * removed or put out of order: a gap in the sequence, a previous hash that is
 * not its predecessor's, a hash that no longer matches the entry's content,
 * or a chain whose last entry is not the one its head records - entries cut
 * off the end. Reads a page at a time, so it runs on a chain of any length.
 *
 * And the seals (AuditChain::seal): an entry or head sealed under none of
 * the chain keys was rewritten by someone without the key, and an unsealed
 * entry after a sealed one was written below the application. Entries from
 * before a key was set have no seal and are checked by their hash alone.
 *
 * Reports only. A break means the log was written to below the application;
 * the remedy is the backup taken before it, not an edit.
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'platform:verify-audit-chain
                            {--organization= : Verify one organization\'s chain only}
                            {--page=1000 : Entries read at a time}';

    protected $description = 'Verify that no audit log entry was changed, removed or reordered';

    private int $errors = 0;

    private int $checked = 0;

    public function handle(): int
    {
        $organization = $this->option('organization');

        $chains = $organization !== null
            ? collect([AuditChain::chainOf((string) $organization)])
            : DB::table('audit_chain_heads')->orderBy('chain')->pluck('chain');

        foreach ($chains as $chain) {
            $this->walk((string) $chain);
        }

        $this->line("Entries checked: {$this->checked}");

        if ($this->errors > 0) {
            $this->error("Audit chain verification FAILED: {$this->errors} problem(s).");

            return self::FAILURE;
        }

        $this->info('Audit chain verification PASSED.');

        return self::SUCCESS;
    }

    private function walk(string $chain): void
    {
        $seq = 0;
        $previous = AuditChain::GENESIS;
        $sealed = false;
        $keys = AuditChain::keys();

        DB::table('audit_logs')
            ->where('chain', $chain)
            ->chunkById(max(1, (int) $this->option('page')), function (Collection $page) use ($chain, &$seq, &$previous, &$sealed, $keys): void {
                foreach ($page as $row) {
                    if ($row->entry_mac !== null) {
                        $sealed = true;

                        if (! $this->sealedBy($keys, (string) $row->entry_hash, (string) $row->entry_mac)) {
                            $this->problem($chain, "entry {$row->chain_seq} ({$row->id}): its seal does not match".($keys === [] ? ' (no AUDIT_CHAIN_KEY is set to check it)' : ''));
                        }
                    } elseif ($sealed) {
                        $this->problem($chain, "entry {$row->chain_seq} ({$row->id}) is not sealed, though entries before it are");
                    }

                    $this->checked++;
                    $seq++;

                    if ((int) $row->chain_seq !== $seq) {
                        $this->problem($chain, "entry {$row->chain_seq} follows entry ".($seq - 1).': entries are missing');
                        $seq = (int) $row->chain_seq;
                    }

                    if ($row->previous_entry_hash !== $previous) {
                        $this->problem($chain, "entry {$row->chain_seq} ({$row->id}) does not follow the entry before it");
                    }

                    if (AuditChain::digest((array) $row, (string) $row->previous_entry_hash) !== $row->entry_hash) {
                        $this->problem($chain, "entry {$row->chain_seq} ({$row->id}) was changed after it was written");
                    }

                    $previous = (string) $row->entry_hash;
                }
            }, 'chain_seq');

        $head = DB::table('audit_chain_heads')->where('chain', $chain)->first();

        if ($head !== null && ((int) $head->last_seq !== $seq || $head->last_hash !== ($seq === 0 ? AuditChain::GENESIS : $previous))) {
            $this->problem($chain, "the chain ends at entry {$seq}, but its last entry was {$head->last_seq}: entries were removed from the end");
        }

        if ($head !== null && ($head->last_mac !== null || $sealed)
            && ! $this->sealedBy($keys, AuditChain::headContent($chain, (int) $head->last_seq, (string) $head->last_hash), (string) $head->last_mac)) {
            $this->problem($chain, 'the seal on its head does not match');
        }

        $this->line("{$chain}: {$seq} entries");
    }

    /** @param list<string> $keys */
    private function sealedBy(array $keys, string $content, string $seal): bool
    {
        foreach ($keys as $key) {
            if (hash_equals((string) AuditChain::seal($content, $key), $seal)) {
                return true;
            }
        }

        return false;
    }

    private function problem(string $chain, string $message): void
    {
        $this->errors++;
        $this->error("  {$chain}: {$message}");
    }
}
