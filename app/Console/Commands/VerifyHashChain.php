<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Compliance\Fatoora\Models\ChainEntry;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verify PIH (Previous Invoice Hash) chain integrity.
 *
 * Walks each tenant's invoices in ICV order and checks that the PIH recorded
 * for every document is the hash of the one before it. Reports only: a break
 * means documents already went to ZATCA carrying the wrong predecessor, and no
 * local edit undoes that. It had a --fix that wrote the corrected value onto
 * the invoice, which repaired nothing even in principle and wrote to a column
 * that does not exist.
 *
 * Used for disaster-recovery verification, audit checks, and detecting chain
 * corruption.
 */
class VerifyHashChain extends Command
{
    protected $signature = 'fatoora:verify-hash-chain
                            {--organization= : Verify specific organization only}
                            {--database= : Use alternate database connection}';

    protected $description = 'Verify PIH (Previous Invoice Hash) chain integrity for ZATCA compliance';

    private int $errors = 0;

    private int $warnings = 0;

    private int $checked = 0;

    public function handle(): int
    {
        // Switch database if specified
        if ($database = $this->option('database')) {
            $this->info("Using database: {$database}");
            config(['database.connections.pgsql.database' => $database]);
            DB::purge('pgsql');
        }

        $this->info('');
        $this->info('========================================');
        $this->info('  ZATCA Hash Chain Verification');
        $this->info('========================================');
        $this->info('');

        // Get organizations to verify
        $organizationId = $this->option('organization');

        if ($organizationId) {
            $this->verifyOrganization($organizationId);
        } else {
            $this->verifyAllOrganizations();
        }

        // Summary
        $this->info('');
        $this->info('========================================');
        $this->info('  Summary');
        $this->info('========================================');
        $this->line("  Invoices checked: {$this->checked}");
        $this->line("  Errors: {$this->errors}");
        $this->line("  Warnings: {$this->warnings}");
        $this->info('');

        if ($this->errors > 0) {
            $this->error('❌ Hash chain verification FAILED');
            $this->error('   Action required: Investigate and repair hash chain');

            return Command::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->warn('⚠️  Hash chain verified with warnings');

            return Command::SUCCESS;
        }

        $this->info('✅ Hash chain verification PASSED');

        return Command::SUCCESS;
    }

    private function verifyAllOrganizations(): void
    {
        $organizations = Invoice::select('org_id')
            ->distinct()
            ->pluck('org_id');

        $this->info("Found {$organizations->count()} organization(s) to verify");
        $this->info('');

        foreach ($organizations as $orgId) {
            $this->verifyOrganization($orgId);
        }
    }

    /**
     * Documents that should be in the chain and are not.
     *
     * The walk follows the counter, so anything without one sits outside it. A
     * draft belongs outside. An invoice that reached Issued without a counter
     * does not: it has been given to a customer and is missing from the record
     * that proves the sequence.
     *
     * Issuance writes status, icv and hash in one transaction, so this is not
     * reachable through the normal path - it would come from an import, a
     * backfill, a manual repair, or a second issuance path somebody adds
     * later. Those are exactly the circumstances where nobody is watching,
     * which is why it is worth checking rather than assuming.
     *
     * The mirror case - a chain entry whose invoice is gone - is not checked,
     * because it cannot happen: hash_chain_history.invoice_id is a foreign key
     * with cascadeOnDelete, so the entry goes with the invoice. A check for a
     * state the schema already forbids is a query on every scheduled run that
     * can never fire, and a reader who finds it reasonably concludes the state
     * is reachable.
     */
    private function strays(string $organizationId): void
    {
        $issuedWithoutCounter = Invoice::where('org_id', $organizationId)
            ->where('status', '!=', 'draft')
            ->whereNull('icv')
            ->get(['id', 'invoice_number', 'status']);

        foreach ($issuedWithoutCounter as $invoice) {
            $this->errors++;
            $this->error(
                // status is cast to InvoiceStatus; interpolating the enum
                // itself is a fatal, not an empty string.
                "  {$invoice->invoice_number} is {$invoice->status->value} but holds no counter, "
                .'so it is outside the chain entirely'
            );
        }

    }

    private function verifyOrganization(string $organizationId): void
    {
        $this->info("Organization: {$organizationId}");
        $this->info(str_repeat('-', 40));

        // Only the documents that are actually in the chain.
        //
        // An invoice joins the chain when it is issued: that is when it is
        // allocated an ICV and hashed. Before then it is a draft with neither,
        // by design. This selected every invoice regardless, so each draft was
        // read as a chain member with a missing hash and an out-of-sequence
        // counter, and one draft was enough to make the whole check report
        // FAILED.
        //
        // That matters because this runs on a schedule - weekly, see
        // routes/console.php - and its failures now reach a human. A scheduled
        // job that always fails is worse than no job: it teaches people to
        // ignore the one check that would tell them the chain has genuinely
        // forked, which is the fault that cannot be repaired afterwards.
        //
        // Filtering on icv costs something, and strays() pays it back: an
        // invoice that is issued but holds no counter is now outside this
        // walk, so it is looked for separately rather than silently skipped.
        $invoices = Invoice::where('org_id', $organizationId)
            ->whereNotNull('icv')
            ->orderBy('icv')
            ->get(['id', 'invoice_number', 'icv', 'hash', 'created_at']);

        $this->strays($organizationId);

        if ($invoices->isEmpty()) {
            $this->line('  No invoices found');

            return;
        }

        // What each document was actually built with. This is the record worth
        // checking: the invoice itself keeps no PIH, and deriving one would
        // only re-walk the chain and agree with itself.
        $recorded = ChainEntry::withoutTenantScope(
            fn () => ChainEntry::where('org_id', $organizationId)->pluck('previous_hash', 'invoice_id')
        );

        // First invoice should have PIH = base64(32 zero bytes)
        $expectedFirstPih = base64_encode(str_repeat("\0", 32));
        $previousHash = $expectedFirstPih;
        $previousIcv = 0;

        foreach ($invoices as $invoice) {
            $this->checked++;

            // Check 1: ICV sequence
            if ($invoice->icv !== $previousIcv + 1) {
                $gap = $invoice->icv - $previousIcv - 1;
                if ($gap > 0) {
                    $this->warn("  ⚠️  ICV gap: {$previousIcv} → {$invoice->icv} (missing {$gap})");
                    $this->warnings++;
                } else {
                    $this->error("  ❌ ICV sequence error: {$previousIcv} → {$invoice->icv}");
                    $this->errors++;
                }
            }

            // Check 2: the PIH the document was built with is the hash of the
            // one before it.
            $carried = $recorded[$invoice->id] ?? null;

            if ($carried === null) {
                $this->error("  ❌ ICV {$invoice->icv} ({$invoice->invoice_number}): no chain entry");
                $this->errors++;
            } elseif ($carried !== $previousHash) {
                $this->error("  ❌ ICV {$invoice->icv} ({$invoice->invoice_number}): PIH mismatch");

                if ($this->option('verbose')) {
                    $this->line("     Expected: {$previousHash}");
                    $this->line("     Got:      {$carried}");
                }

                $this->errors++;
            } elseif ($this->option('verbose')) {
                $this->line("  ✓ ICV {$invoice->icv}: OK");
            }

            // Check 3: Hash is not empty
            if (empty($invoice->hash)) {
                $this->error("  ❌ ICV {$invoice->icv}: Missing hash");
                $this->errors++;
            }

            // Move to next
            $previousHash = $invoice->hash;
            $previousIcv = $invoice->icv;
        }

        $this->info("  Verified {$invoices->count()} invoices");
        $this->info('');
    }
}
