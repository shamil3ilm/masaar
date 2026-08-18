<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\Services\Connectivity;
use App\Domains\Compliance\Fatoora\Services\OfflineQueue;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Process invoices from the offline queue.
 *
 * Run this command when:
 * - Network connectivity is restored after offline period
 * - ZATCA API is back online after maintenance
 * - Manual sync is needed for POS/retail systems
 *
 * Scheduler recommendation: Run every 5 minutes
 * `$schedule->command('fatoora:process-offline')->everyFiveMinutes();`
 */
class ProcessOfflineQueue extends Command
{
    protected $signature = 'fatoora:process-offline
                            {--organization= : Process specific organization only}
                            {--limit=50 : Maximum items to process per run}
                            {--dry-run : Check connectivity and show queue status without processing}
                            {--force : Process even if connectivity check fails}';

    protected $description = 'Process queued invoices from offline mode and submit to ZATCA';

    private int $processed = 0;

    private int $succeeded = 0;

    private int $failed = 0;

    private int $skipped = 0;

    public function __construct(
        private readonly OfflineQueue $queueManager,
        private readonly FatooraClient $zatcaClient,
        private readonly ?Connectivity $connectivityChecker = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('');
        $this->info('========================================');
        $this->info('  ZATCA Offline Queue Processor');
        $this->info('========================================');
        $this->info('');

        $organizationId = $this->option('organization');
        $limit = (int) $this->option('limit');
        $isDryRun = $this->option('dry-run');
        $force = $this->option('force');

        // Step 1: Check ZATCA connectivity
        if (! $force) {
            $this->info('Checking ZATCA API connectivity...');

            if ($this->connectivityChecker) {
                $connectivity = $this->connectivityChecker->check();

                if (! $connectivity['available']) {
                    $this->error('ZATCA API is not available: '.($connectivity['reason'] ?? 'Unknown'));
                    $this->line('  Use --force to process anyway');

                    return Command::FAILURE;
                }

                $this->info("✓ ZATCA API is available (latency: {$connectivity['latency_ms']}ms)");
            } else {
                $this->warn('Connectivity checker not available, proceeding anyway...');
            }
        }

        $this->info('');

        // Step 2: Get queue status
        if ($organizationId) {
            $this->processOrganization($organizationId, $limit, $isDryRun);
        } else {
            $this->processAllOrganizations($limit, $isDryRun);
        }

        // Step 3: Summary
        $this->info('');
        $this->info('========================================');
        $this->info('  Summary');
        $this->info('========================================');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $this->processed],
                ['Succeeded', $this->succeeded],
                ['Failed', $this->failed],
                ['Skipped', $this->skipped],
            ]
        );

        if ($isDryRun) {
            $this->warn('This was a dry run. No invoices were actually submitted.');
        }

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processAllOrganizations(int $limit, bool $isDryRun): void
    {
        // Get all organizations with pending items
        $organizations = DB::table('offline_queue')
            ->where('state', OfflineQueue::STATE_PENDING)
            ->distinct()
            ->pluck('org_id');

        if ($organizations->isEmpty()) {
            $this->info('No pending items in offline queue.');

            return;
        }

        $this->info("Found {$organizations->count()} organization(s) with pending items");
        $this->info('');

        foreach ($organizations as $orgId) {
            $this->processOrganization($orgId, $limit, $isDryRun);
        }
    }

    /**
     * Drain one organization's queue.
     *
     * The work runs inside runAs() so tenant scoping applies for its duration:
     * the command walks every organization in turn, and a query here that
     * forgot its own filter would otherwise submit another taxpayer's invoices.
     */
    private function processOrganization(string $organizationId, int $limit, bool $isDryRun): void
    {
        app(TenantResolver::class)->runAs(
            $organizationId,
            fn () => $this->drain($organizationId, $limit, $isDryRun)
        );
    }

    private function drain(string $organizationId, int $limit, bool $isDryRun): void
    {
        $organization = Organization::find($organizationId);

        if (! $organization) {
            $this->warn("Organization {$organizationId} not found, skipping...");

            return;
        }

        $this->info("Processing: {$organization->name}");
        $this->info(str_repeat('-', 40));

        // Get queue status
        $status = $this->queueManager->getStatus($organizationId);
        $this->line("  Pending: {$status['pending']}");
        $this->line("  Processing: {$status['processing']}");
        $this->line("  Completed: {$status['completed']}");
        $this->line("  Failed: {$status['failed']}");

        if ($status['pending'] === 0) {
            $this->line('  No pending items.');

            return;
        }

        if ($isDryRun) {
            $this->line("  Would process up to {$limit} items.");

            return;
        }

        // Get batch to process
        $batch = $this->queueManager->getNextBatch($organizationId, $limit);

        if (empty($batch)) {
            $this->line('  No items ready for processing.');

            return;
        }

        $this->line("  Processing {count($batch)} items...");
        $this->info('');

        $progressBar = $this->output->createProgressBar(count($batch));
        $progressBar->start();

        foreach ($batch as $item) {
            $this->processItem($item, $organization);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->info('');
        $this->info('');
    }

    private function processItem(object $item, Organization $organization): void
    {
        $this->processed++;

        // Validate item before processing
        $validation = $this->queueManager->validateQueuedItem($item);

        if (! $validation['valid']) {
            $this->handleInvalidItem($item, $validation);

            return;
        }

        // Mark as processing
        $this->queueManager->markProcessing($item->id);

        try {
            // Get invoice
            $invoice = Invoice::find($item->invoice_id);

            if (! $invoice) {
                $this->queueManager->markFailed($item->id, 'Invoice not found', false);
                $this->failed++;

                return;
            }

            // Submit to ZATCA
            $response = $invoice->isB2B()
                ? $this->zatcaClient->clearInvoice(
                    $item->signed_xml,
                    $item->invoice_hash,
                    $invoice->id
                )
                : $this->zatcaClient->reportInvoice(
                    $item->signed_xml,
                    $item->invoice_hash,
                    $invoice->id
                );

            if ($response->success) {
                $this->queueManager->markCompleted($item->id, [
                    'clearanceStatus' => $response->clearanceStatus,
                    'reportingStatus' => $response->reportingStatus,
                    'invoiceUuid' => $response->validationResults['invoiceUuid'] ?? null,
                ]);

                // Update invoice status
                $invoice->update([
                    'zatca_status' => $invoice->isB2B() ? 'cleared' : 'reported',
                    'zatca_cleared_at' => now(),
                ]);

                $this->succeeded++;

                Log::info('Offline queue item submitted successfully', [
                    'queue_id' => $item->id,
                    'invoice_id' => $invoice->id,
                ]);
            } else {
                $errorMessage = implode('; ', $response->errorMessages ?? ['Unknown error']);
                $this->queueManager->markFailed($item->id, $errorMessage, true);
                $this->failed++;

                Log::warning('Offline queue item submission failed', [
                    'queue_id' => $item->id,
                    'invoice_id' => $invoice->id,
                    'error' => $errorMessage,
                ]);
            }
        } catch (\Throwable $e) {
            $this->queueManager->markFailed($item->id, $e->getMessage(), true);
            $this->failed++;

            Log::error('Offline queue processing error', [
                'queue_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleInvalidItem(object $item, array $validation): void
    {
        $action = $validation['action'];

        switch ($action) {
            case 'skip':
                // Already submitted, mark as completed
                $this->queueManager->markCompleted($item->id, [
                    'skipped' => true,
                    'reason' => $validation['reason'],
                    'existing_submission_id' => $validation['existing_submission_id'] ?? null,
                ]);
                $this->skipped++;
                break;

            case 'resign':
                // Needs re-signing, keep in queue with error
                $this->queueManager->markFailed(
                    $item->id,
                    'Certificate changed - needs re-signing: '.$validation['reason'],
                    false
                );
                $this->skipped++;
                break;

            case 'regenerate_icv':
                // ICV conflict, needs manual intervention
                $this->queueManager->markFailed(
                    $item->id,
                    'ICV conflict - manual intervention required: '.$validation['reason'],
                    false
                );
                $this->failed++;
                break;

            default:
                $this->queueManager->markFailed($item->id, $validation['reason'] ?? 'Unknown validation error', false);
                $this->failed++;
        }

        Log::info('Offline queue item validation failed', [
            'queue_id' => $item->id,
            'action' => $action,
            'reason' => $validation['reason'],
        ]);
    }
}
