<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Compliance\Fatoora\Enums\RequeueOutcome;
use App\Domains\Compliance\Fatoora\Services\Requeuer;
use App\Domains\Platform\DTOs\FilterData;
use App\Domains\Platform\Services\OrganizationReport;
use App\Domains\Platform\Services\QueueReport;
use App\Domains\Platform\Services\SubmissionLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Web Admin Dashboard Controller.
 *
 * Provides Blade-based admin views for Masaar internal use. Every page spans
 * all tenants; the reports it calls read past tenant scoping by design.
 */
class AdminController extends Controller
{
    public function __construct(
        private readonly OrganizationReport $organizations,
        private readonly QueueReport $queue,
        private readonly SubmissionLog $submissionLog,
        private readonly Requeuer $requeuer,
    ) {}

    /**
     * Main dashboard view.
     */
    public function dashboard(): View
    {
        return view('admin.dashboard');
    }

    /**
     * Organizations list view.
     */
    public function organizations(Request $request): View
    {
        $organizations = $this->organizations->page(20);
        $submissionStats = $this->organizations->acceptedSubmissions($organizations->pluck('id'));

        return view('admin.organizations', compact('organizations', 'submissionStats'));
    }

    /**
     * Organization detail view.
     */
    public function organizationDetail(string $id): View
    {
        $organization = $this->organizations->find($id);

        if (! $organization) {
            abort(404);
        }

        $stats = $this->organizations->stats($id);
        $recentSubmissions = $this->organizations->recentSubmissions($id, 20);
        $certificate = $this->organizations->certificate($id);

        return view('admin.organization-detail', compact('organization', 'stats', 'recentSubmissions', 'certificate'));
    }

    /**
     * Offline queue view.
     */
    public function queue(Request $request): View
    {
        $state = $request->query('state');
        $orgId = $request->query('org_id');

        $items = $this->queue->page(FilterData::fromQuery($state, $orgId), 50);
        $stats = $this->queue->countByState();

        return view('admin.queue', compact('items', 'stats', 'state', 'orgId'));
    }

    /**
     * Submission logs view.
     */
    public function logs(Request $request): View
    {
        $state = $request->query('state');
        $orgId = $request->query('org_id');

        $logs = $this->submissionLog->page(FilterData::fromQuery($state, $orgId), 50);
        $stateCounts = $this->submissionLog->countByState();

        return view('admin.logs', compact('logs', 'stateCounts', 'state', 'orgId'));
    }

    /**
     * Drain a batch of the offline submission queue.
     *
     * Runs a privileged Artisan command, so the acting administrator is
     * recorded.
     */
    public function processQueue(): RedirectResponse
    {
        Log::info('Offline queue processing triggered from admin console', [
            'actor_id' => Auth::id(),
        ]);

        Artisan::call('fatoora:process-offline', ['--limit' => 50]);

        return back()->with('success', 'Queue processing started');
    }

    /**
     * Requeue a failed offline item for another submission attempt.
     */
    public function retryQueueItem(string $id): RedirectResponse
    {
        $outcome = $this->requeuer->requeue($id);

        if ($outcome === RequeueOutcome::NotFound) {
            return back()->with('error', 'Queue item not found');
        }

        if ($outcome === RequeueOutcome::NotFailed) {
            return back()->with('error', 'Only failed items can be retried');
        }

        Log::info('Offline queue item requeued from admin console', [
            'actor_id' => Auth::id(),
            'queue_item_id' => $id,
        ]);

        return back()->with('success', 'Item queued for retry');
    }
}
