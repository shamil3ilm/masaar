<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Enums\RequeueOutcome;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Requeuer;
use App\Domains\Organization\Services\SetupChecklist;
use App\Domains\Platform\DTOs\FilterData;
use App\Domains\Platform\Services\ArabicNameReport;
use App\Domains\Platform\Services\FeatureReport;
use App\Domains\Platform\Services\OrganizationReport;
use App\Domains\Platform\Services\PlatformStatus;
use App\Domains\Platform\Services\QueueReport;
use App\Domains\Platform\Services\SubmissionLog;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        private readonly PlatformStatus $status,
        private readonly FeatureReport $features,
        private readonly ArabicNameReport $arabicNames,
        private readonly Lists $lists,
        private readonly SetupChecklist $setup,
    ) {}

    /**
     * Main dashboard view.
     *
     * Rendered from the same service the JSON endpoints read. The page used to
     * fetch five /api/admin/* routes from the browser; those are behind
     * jwt.auth, a session cookie is not a JWT, and every `|| {}` fallback drew
     * the resulting 401 as a zero.
     */
    public function dashboard(): View
    {
        return view('admin.dashboard', [
            'overview' => $this->status->overview(),
            'health' => $this->status->health(),
        ]);
    }

    /**
     * Organizations list view.
     */
    public function organizations(Request $request): View
    {
        // ?status=pending_review lists the sign-ups waiting for a decision.
        $list = $this->lists->admin($this->organizations->spec('admin.organizations'), $request, 'admin.organizations.export');
        $organizations = $this->organizations->history($list);
        $submissionStats = $this->organizations->acceptedSubmissions($organizations->pluck('id'));

        return view('admin.organizations', compact('organizations', 'submissionStats', 'list'));
    }

    /** Every organization the list's filters give, as a CSV. */
    public function exportOrganizations(Request $request): StreamedResponse
    {
        $list = $this->lists->admin($this->organizations->spec('admin.organizations'), $request);

        return $this->organizations->csv($list, 'organizations-'.now()->format('Y-m-d').'.csv');
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
        $members = $this->organizations->members($id);
        $features = $this->features->rows($id);
        // Where its country requires Arabic: the grace and what is missing.
        $arabic = $this->arabicNames->summary($id);
        $peopleSwitches = $this->features->people($id);
        // Its setup, as its own dashboard shows it: the country's steps too,
        // such as appointing a UAE service provider.
        $setup = $this->setup->stepsFor($id);

        return view('admin.organization-detail', compact('organization', 'stats', 'recentSubmissions', 'certificate', 'members', 'features', 'arabic', 'peopleSwitches', 'setup'));
    }

    /**
     * Offline queue view.
     */
    public function queue(Request $request): View
    {
        $list = $this->lists->admin($this->queue->spec('admin.queue'), $request, 'admin.queue.export');
        $items = $this->queue->history($list);
        $stats = $this->queue->countByState();

        return view('admin.queue', [
            'items' => $items,
            'stats' => $stats,
            'list' => $list,
            'state' => $list->value('state'),
            'orgId' => $list->value('org_id'),
        ]);
    }

    /** Every queue item the list's filters give, as a CSV. */
    public function exportQueue(Request $request): StreamedResponse
    {
        $list = $this->lists->admin($this->queue->spec('admin.queue'), $request);

        return $this->queue->csv($list, 'offline-queue-'.now()->format('Y-m-d').'.csv');
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

        return back()->with('success', 'Queue processing started.');
    }

    /**
     * Requeue a failed offline item for another submission attempt.
     */
    public function retryQueueItem(string $id): RedirectResponse
    {
        $outcome = $this->requeuer->requeue($id);

        if ($outcome === RequeueOutcome::NotFound) {
            return back()->with('error', 'Queue item not found.');
        }

        if ($outcome === RequeueOutcome::NotFailed) {
            return back()->with('error', 'Only failed items can be retried.');
        }

        Log::info('Offline queue item requeued from admin console', [
            'actor_id' => Auth::id(),
            'queue_item_id' => $id,
        ]);

        return back()->with('success', 'Item queued for retry.');
    }
}
