<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Invoice\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verify PIH (Previous Invoice Hash) chain integrity.
 *
 * Used for:
 * - Disaster recovery verification
 * - Audit compliance checks
 * - Detecting hash chain corruption
 */
class VerifyHashChain extends Command
{
    protected $signature = 'zatca:verify-hash-chain
                            {--organization= : Verify specific organization only}
                            {--database= : Use alternate database connection}
                            {--fix : Attempt to fix minor issues}
                            {--verbose : Show detailed output}';

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
        $organizations = Invoice::select('organization_id')
            ->distinct()
            ->pluck('organization_id');

        $this->info("Found {$organizations->count()} organization(s) to verify");
        $this->info('');

        foreach ($organizations as $orgId) {
            $this->verifyOrganization($orgId);
        }
    }

    private function verifyOrganization(string $organizationId): void
    {
        $this->info("Organization: {$organizationId}");
        $this->info(str_repeat('-', 40));

        // Get all invoices ordered by ICV
        $invoices = Invoice::where('organization_id', $organizationId)
            ->orderBy('icv')
            ->get(['id', 'invoice_number', 'icv', 'hash', 'previous_invoice_hash', 'created_at']);

        if ($invoices->isEmpty()) {
            $this->line('  No invoices found');
            return;
        }

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

            // Check 2: PIH matches previous invoice hash
            if ($invoice->previous_invoice_hash !== $previousHash) {
                $this->error("  ❌ ICV {$invoice->icv} ({$invoice->invoice_number}): PIH mismatch");

                if ($this->option('verbose')) {
                    $this->line("     Expected: {$previousHash}");
                    $this->line("     Got:      {$invoice->previous_invoice_hash}");
                }

                $this->errors++;

                // Attempt fix if requested
                if ($this->option('fix') && $this->confirm("     Attempt to fix PIH for ICV {$invoice->icv}?")) {
                    $invoice->previous_invoice_hash = $previousHash;
                    $invoice->save();
                    $this->info("     ✅ Fixed PIH for ICV {$invoice->icv}");
                }
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
