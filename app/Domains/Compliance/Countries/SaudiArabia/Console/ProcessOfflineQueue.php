<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\FatooraClient;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineQueue;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
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
 * Scheduled every five minutes (routes/console.php).
 *
 * Organizations take turns: a slice from each, the one waiting longest
 * first, round after round, until each has sent its share for the run
 * (fatoora.offline.drain) or the run's time is up. It took up to fifty from
 * one organization and then the next, so one long outage held every other
 * organization back, and fifty every five minutes is short of a busy store's
 * day against the twenty-four-hour reporting window.
 */
class ProcessOfflineQueue extends Command
{
    protected $signature = 'fatoora:process-offline
                            {--organization= : Process specific organization only}
                            {--limit= : Maximum items per organization this run (default: fatoora.offline.drain.per_organization)}
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
        private readonly CredentialStore $credentials,
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
        $limit = (int) ($this->option('limit') ?? config('fatoora.offline.drain.per_organization', 1000));
        $isDryRun = $this->option('dry-run');
        $force = $this->option('force');

        // What a drain that died left processing goes back to pending first,
        // or nothing would ever pick it up. A dry run changes nothing.
        if (! $isDryRun && ($released = $this->queueManager->releaseStranded()) > 0) {
            $this->warn("Released {$released} item(s) a previous run left processing.");
        }

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
        $this->takeTurns($organizationId ? [$organizationId] : $this->waiting(), $limit, $isDryRun);

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

    /**
     * The organizations with items waiting, the one waiting longest first:
     * its reporting deadline is the nearest.
     *
     * @return list<string>
     */
    private function waiting(): array
    {
        $organizations = DB::table('offline_queue')
            ->where('state', OfflineQueue::STATE_PENDING)
            ->groupBy('org_id')
            ->orderByRaw('MIN(queued_at)')
            ->pluck('org_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($organizations === []) {
            $this->info('No pending items in offline queue.');
        } else {
            $this->info('Found '.count($organizations).' organization(s) with pending items');
            $this->info('');
        }

        return $organizations;
    }

    /**
     * A slice from each organization in turn, round after round.
     *
     * An organization leaves the rotation when it has nothing more ready or
     * has sent its share for the run. The run stops taking more when its
     * time is up, so it ends before the next one is due (the scheduler's
     * overlap lock would otherwise skip that one), and when a whole round
     * filed nothing but failed something: the authority is turning documents
     * away, and the retry schedule, not this loop, decides when to try again.
     *
     * @param  list<string>  $organizations
     */
    private function takeTurns(array $organizations, int $limit, bool $isDryRun): void
    {
        $slice = max(1, (int) config('fatoora.offline.drain.slice', 25));
        $stopAt = microtime(true) + max(1, (int) config('fatoora.offline.drain.max_seconds', 240));
        $sent = array_fill_keys($organizations, 0);
        $first = true;

        while ($sent !== [] && microtime(true) < $stopAt) {
            [$succeeded, $failed] = [$this->succeeded, $this->failed];

            foreach (array_keys($sent) as $organizationId) {
                $take = min($slice, $limit - $sent[$organizationId]);
                $taken = $this->processOrganization((string) $organizationId, $take, $isDryRun, $first);
                $sent[$organizationId] += $taken;

                if ($taken < $take || $sent[$organizationId] >= $limit) {
                    unset($sent[$organizationId]);
                }

                if (microtime(true) >= $stopAt) {
                    break;
                }
            }

            $first = false;

            if ($this->succeeded === $succeeded && $this->failed > $failed) {
                $this->warn('Nothing was filed this round; the rest waits for the next run.');

                break;
            }
        }
    }

    /**
     * Drain one organization's queue.
     *
     * The work runs inside runAs() so tenant scoping applies for its duration:
     * the command walks every organization in turn, and a query here that
     * forgot its own filter would otherwise submit another taxpayer's invoices.
     *
     * @return int The items taken
     */
    private function processOrganization(string $organizationId, int $limit, bool $isDryRun, bool $first): int
    {
        return (int) app(TenantResolver::class)->runAs(
            $organizationId,
            fn () => $this->drain($organizationId, $limit, $isDryRun, $first)
        );
    }

    /**
     * @return int The items taken
     */
    private function drain(string $organizationId, int $limit, bool $isDryRun, bool $first): int
    {
        $organization = Organization::find($organizationId);

        if (! $organization) {
            $this->warn("Organization {$organizationId} not found, skipping...");

            return 0;
        }

        $this->info("Processing: {$organization->name}");
        $this->info(str_repeat('-', 40));

        // Its queue, once a run.
        if ($first) {
            $status = $this->queueManager->getStatus($organizationId);
            $this->line("  Pending: {$status['pending']}");
            $this->line("  Processing: {$status['processing']}");
            $this->line("  Completed: {$status['completed']}");
            $this->line("  Failed: {$status['failed']}");

            if ($status['pending'] === 0) {
                $this->line('  No pending items.');

                return 0;
            }
        }

        if ($isDryRun) {
            $this->line("  Would process up to {$limit} items.");

            return 0;
        }

        // Get batch to process
        $batch = $this->queueManager->getNextBatch($organizationId, $limit);

        if (empty($batch)) {
            $this->line('  No items ready for processing.');

            return 0;
        }

        $this->line('  Processing '.count($batch).' items...');
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

        return count($batch);
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

        // Taken, or already taken by another drain - in which case it is
        // that drain's to send.
        if (! $this->queueManager->claim($item->id)) {
            $this->skipped++;

            return;
        }

        try {
            $invoice = Invoice::find($item->invoice_id);

            if (! $invoice) {
                $this->queueManager->markFailed($item->id, 'Invoice not found', false);
                $this->failed++;

                return;
            }

            $response = $this->send($item, $invoice);
        } catch (\Throwable $e) {
            $this->queueManager->markFailed($item->id, $e->getMessage(), true);
            $this->failed++;

            Log::error('Offline queue processing error', [
                'queue_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // A clearance that may have reached ZATCA - a read timeout, a 5xx
        // after the request went out - is not sent again: ZATCA could have
        // cleared it already, and a second clearance files it twice. It is
        // held, kept, and reported for reconciliation.
        if (! $response->success && $response->isRetryable() && $response->mayHaveArrived && $invoice->isB2B()) {
            $errorMessage = implode('; ', $response->errorMessages ?: ['Unknown error']);
            $this->queueManager->markFailed($item->id, 'Held for reconciliation: '.$errorMessage, false);
            $this->failed++;

            Log::critical('An offline clearance may have reached ZATCA without an answer; reconcile with ZATCA before it is sent again', [
                'queue_id' => $item->id,
                'invoice_id' => $invoice->id,
                'error' => $errorMessage,
            ]);

            return;
        }

        // Busy, down or throttled: the authority has not looked at the
        // document, so it goes back in the queue.
        if (! $response->success && $response->isRetryable()) {
            $errorMessage = implode('; ', $response->errorMessages ?: ['Unknown error']);
            $this->queueManager->markFailed($item->id, $errorMessage, true);
            $this->failed++;

            Log::warning('Offline queue item submission failed', [
                'queue_id' => $item->id,
                'invoice_id' => $invoice->id,
                'error' => $errorMessage,
            ]);

            return;
        }

        // Anything else is the authority's answer. A refusal was handled as
        // the busy case above: resent until the attempts ran out and recorded
        // nowhere, after which the recovery job filed it once more.
        if (! $response->success) {
            $this->recordRejection($item, $invoice, $response);

            return;
        }

        $this->recordAcceptance($item, $invoice, $response);
    }

    private function send(object $item, Invoice $invoice): FatooraResponse
    {
        // The device that signed the queued document files it.
        $device = $this->credentials->forInvoice((string) $invoice->org_id, $invoice->device_id, $invoice->branch_id, (string) $invoice->id, $item->signed_xml);

        return $invoice->isB2B()
            ? $this->zatcaClient->clearInvoice($item->signed_xml, $item->invoice_hash, $invoice->id, $device)
            : $this->zatcaClient->reportInvoice($item->signed_xml, $item->invoice_hash, $invoice->id, $device);
    }

    /**
     * Record an accepted item, outside processItem()'s retrying error handling.
     *
     * The authority holds the document, so a local failure must not requeue
     * it for a second submission. The item stays processing, a state the queue
     * never picks up again, and the failure is logged for reconciliation.
     */
    private function recordAcceptance(object $item, Invoice $invoice, FatooraResponse $response): void
    {
        try {
            $this->queueManager->complete($item->id, $invoice, $response);
        } catch (\Throwable $e) {
            $this->failed++;

            Log::critical('ZATCA accepted an offline queue item but it could not be recorded', [
                'queue_id' => $item->id,
                'invoice_id' => $invoice->id,
                'exception' => $e,
            ]);

            return;
        }

        $this->succeeded++;

        Log::info('Offline queue item submitted successfully', [
            'queue_id' => $item->id,
            'invoice_id' => $invoice->id,
        ]);
    }

    /**
     * Record a refusal. Outside processItem()'s retrying error handling for
     * the same reason as recordAcceptance(): the authority has answered, and
     * a local failure must not put the document back in the queue.
     */
    private function recordRejection(object $item, Invoice $invoice, FatooraResponse $response): void
    {
        $this->failed++;

        try {
            $this->queueManager->reject($item->id, $invoice, $response);
        } catch (\Throwable $e) {
            Log::critical('ZATCA rejected an offline queue item but it could not be recorded', [
                'queue_id' => $item->id,
                'invoice_id' => $invoice->id,
                'exception' => $e,
            ]);

            return;
        }

        Log::warning('Offline queue item rejected by ZATCA', [
            'queue_id' => $item->id,
            'invoice_id' => $invoice->id,
            'errors' => $response->errorMessages,
        ]);
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
