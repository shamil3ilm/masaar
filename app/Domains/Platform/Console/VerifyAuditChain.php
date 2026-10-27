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

        DB::table('audit_logs')
            ->where('chain', $chain)
            ->chunkById(max(1, (int) $this->option('page')), function (Collection $page) use ($chain, &$seq, &$previous): void {
                foreach ($page as $row) {
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

        $this->line("{$chain}: {$seq} entries");
    }

    private function problem(string $chain, string $message): void
    {
        $this->errors++;
        $this->error("  {$chain}: {$message}");
    }
}
