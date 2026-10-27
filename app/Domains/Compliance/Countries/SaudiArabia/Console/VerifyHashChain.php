<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console;

use App\Domains\Compliance\Countries\SaudiArabia\Models\ChainEntry;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
                            {--database= : Use alternate database connection}
                            {--page=1000 : Invoices read at a time}';

    protected $description = 'Verify PIH (Previous Invoice Hash) chain integrity for ZATCA compliance';

    private int $errors = 0;

    private int $warnings = 0;

    private int $checked = 0;

    public function handle(): int
    {
        // Switch database if specified.
        //
        // The connection name comes from config rather than being written
        // here. It said 'pgsql', and this application runs on mysql - so
        // --database rewrote the configuration of a connection nothing uses,
        // purged it, and then verified the default database anyway while
        // reporting the name it had been given.
        //
        // That is the disaster-recovery path in docs/operations/BACKUP-AND-RESTORE.md:
        // the test restores a backup into a scratch database and runs this to
        // prove the chain survived. It was reading production, so the test
        // passed without the restored copy being looked at once - and passing
        // was the outcome that stopped anybody asking.
        if ($database = $this->option('database')) {
            $connection = (string) config('database.default');

            $this->info("Using database: {$database} (on the {$connection} connection)");

            config(["database.connections.{$connection}.database" => $database]);
            DB::purge($connection);
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
        $this->strays($organizationId);

        $devices = Invoice::where('org_id', $organizationId)
            ->whereNotNull('icv')
            ->distinct()
            ->pluck('device_id');

        if ($devices->isEmpty()) {
            $this->line('  No invoices found');

            return;
        }

        $before = $this->checked;

        // One chain per device, as ZATCA keeps them. Walked as one sequence,
        // a branch's documents read as gaps in head office's counter and as
        // PIH mismatches in both.
        foreach ($devices as $deviceId) {
            // Head office's device is the organization itself.
            if ($deviceId !== null && $deviceId !== $organizationId) {
                $this->line("  Device {$deviceId}");
            }

            $this->walk($organizationId, $deviceId);
        }

        $verified = $this->checked - $before;

        $this->info("  Verified {$verified} invoices");
        $this->info('');
    }

    /**
     * One device's chain, in counter order, a page at a time.
     *
     * It read the whole tenant into memory - every chained invoice, then a map
     * of every chain entry - and a tenant issuing a few thousand documents a
     * day reaches the worker's memory limit within the year, at which point
     * the weekly check dies instead of reporting.
     *
     * Paged on the counter, which (device_id, icv) keeps unique.
     */
    private function walk(string $organizationId, ?string $deviceId): void
    {
        // A device's first document names ZATCA's genesis PIH. This expected
        // base64 of thirty-two zero bytes, which ZATCA refuses and the
        // platform never writes, so every chain's first document was reported
        // as a PIH mismatch and the weekly check said FAILED for every tenant
        // - the outcome that teaches people to ignore it.
        $previousHash = FatooraConfig::GENESIS_PIH;
        $previousIcv = 0;

        Invoice::where('org_id', $organizationId)
            ->whereNotNull('icv')
            ->when(
                $deviceId === null,
                fn ($query) => $query->whereNull('device_id'),
                fn ($query) => $query->where('device_id', $deviceId),
            )
            ->select(['id', 'invoice_number', 'icv', 'hash', 'device_id'])
            ->chunkById(max(1, (int) $this->option('page')), function (Collection $page) use ($organizationId, &$previousHash, &$previousIcv): void {
                // What each document was actually built with. This is the
                // record worth checking: the invoice itself keeps no PIH, and
                // deriving one would only re-walk the chain and agree with
                // itself.
                $recorded = ChainEntry::withoutTenantScope(fn () => ChainEntry::where('org_id', $organizationId)
                    ->whereIn('invoice_id', $page->modelKeys())
                    ->pluck('previous_hash', 'invoice_id'));

                [$previousHash, $previousIcv] = $this->check($page, $recorded, $previousHash, $previousIcv);
            }, 'icv');
    }

    /**
     * Checks one page, carrying the chain's position in and out.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @param  Collection<string, string>  $recorded  invoice id => the PIH it was built with
     * @return array{0: string|null, 1: int} the last hash and counter seen
     */
    private function check(Collection $invoices, Collection $recorded, ?string $previousHash, int $previousIcv): array
    {
        foreach ($invoices as $invoice) {
            $this->checked++;

            // Check 1: ICV sequence
            if ($invoice->icv !== $previousIcv + 1) {
                $gap = $invoice->icv - $previousIcv - 1;
                // An error, not a warning. The counter is allocated at
                // issuance, under the lock that writes the hash, so a device's
                // sequence is contiguous by construction: a gap is a document
                // that was issued and is now missing. It was only a warning,
                // and ChainVerifierScopeTest's gap case passed solely because
                // the genesis check failed every chain anyway.
                if ($gap > 0) {
                    $this->error("  ❌ ICV gap: {$previousIcv} → {$invoice->icv} (missing {$gap})");
                    $this->errors++;
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

        return [$previousHash, $previousIcv];
    }
}
