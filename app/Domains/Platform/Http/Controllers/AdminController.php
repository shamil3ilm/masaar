<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Compliance\Fatoora\Services\CertificateService;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Web Admin Dashboard Controller.
 *
 * Provides Blade-based admin views for Masaar internal use.
 * Uses API endpoints for data to keep logic DRY.
 */
class AdminController extends Controller
{
    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly CertificateService $certificates,
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
        // Expiry comes from the certificate the platform holds. This used to
        // join certificate_lineage, a table nothing ever wrote, so the column
        // was null for every organization and the screen showed no expiry at
        // all. One decryption per row on a paginated admin screen.
        $organizations = DB::table('organizations')
            ->select([
                'organizations.id',
                'organizations.name',
                'organizations.vat_number',
                'organizations.status',
                'organizations.created_at',
            ])
            ->orderByDesc('organizations.created_at')
            ->paginate(20);

        $organizations->getCollection()->transform(function ($org) {
            $details = $this->certificates->details(
                $this->credentials->certificate((string) $org->id)
            );

            $org->cert_expires_at = $details['valid_to'] ?? null;

            return $org;
        });

        // Get submission stats per org
        $orgIds = $organizations->pluck('id');
        $submissionStats = DB::table('invoice_submissions')
            ->whereIn('org_id', $orgIds)
            ->selectRaw('org_id, COUNT(*) as total, SUM(CASE WHEN state IN ("cleared", "reported") THEN 1 ELSE 0 END) as successful')
            ->groupBy('org_id')
            ->pluck('successful', 'org_id');

        return view('admin.organizations', compact('organizations', 'submissionStats'));
    }

    /**
     * Organization detail view.
     */
    public function organizationDetail(string $id): View
    {
        $organization = DB::table('organizations')->where('id', $id)->first();

        if (! $organization) {
            abort(404);
        }

        // Optimized: Single query for invoice count
        $invoiceCount = DB::table('invoices')->where('org_id', $id)->count();

        // Optimized: Single query for all submission stats
        $submissionStats = DB::table('invoice_submissions')
            ->where('org_id', $id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN state = 'cleared' THEN 1 ELSE 0 END) as cleared,
                SUM(CASE WHEN state = 'rejected' THEN 1 ELSE 0 END) as rejected
            ")
            ->first();

        $stats = [
            'invoices' => $invoiceCount,
            'submissions' => (int) ($submissionStats->total ?? 0),
            'cleared' => (int) ($submissionStats->cleared ?? 0),
            'rejected' => (int) ($submissionStats->rejected ?? 0),
        ];

        $recentSubmissions = DB::table('invoice_submissions')
            ->where('org_id', $id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $details = $this->certificates->details(
            $this->credentials->certificate((string) $id)
        );

        $certificate = $details === null ? null : (object) $details;

        return view('admin.organization-detail', compact('organization', 'stats', 'recentSubmissions', 'certificate'));
    }

    /**
     * Offline queue view.
     */
    public function queue(Request $request): View
    {
        $state = $request->query('state');
        $orgId = $request->query('org_id');

        $query = DB::table('offline_queue')
            ->leftJoin('organizations', 'offline_queue.org_id', '=', 'organizations.id')
            ->select([
                'offline_queue.*',
                'organizations.name as organization_name',
            ])
            ->orderByDesc('offline_queue.queued_at');

        if ($state) {
            $query->where('offline_queue.state', $state);
        }

        if ($orgId) {
            $query->where('offline_queue.org_id', $orgId);
        }

        $items = $query->paginate(50);

        $stats = DB::table('offline_queue')
            ->selectRaw('state, COUNT(*) as count')
            ->groupBy('state')
            ->pluck('count', 'state');

        return view('admin.queue', compact('items', 'stats', 'state', 'orgId'));
    }

    /**
     * Submission logs view.
     */
    public function logs(Request $request): View
    {
        $state = $request->query('state');
        $orgId = $request->query('org_id');

        $query = DB::table('invoice_submissions')
            ->leftJoin('organizations', 'invoice_submissions.org_id', '=', 'organizations.id')
            ->select([
                'invoice_submissions.*',
                'organizations.name as organization_name',
            ])
            ->orderByDesc('invoice_submissions.created_at');

        if ($state) {
            $query->where('invoice_submissions.state', $state);
        }

        if ($orgId) {
            $query->where('invoice_submissions.org_id', $orgId);
        }

        $logs = $query->paginate(50);

        $stateCounts = DB::table('invoice_submissions')
            ->selectRaw('state, COUNT(*) as count')
            ->groupBy('state')
            ->pluck('count', 'state');

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
     * Requeue a single offline item for another submission attempt.
     */
    public function retryQueueItem(string $id): RedirectResponse
    {
        $updated = DB::table('offline_queue')
            ->where('id', $id)
            ->update([
                'state' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return back()->with('error', 'Queue item not found');
        }

        Log::info('Offline queue item requeued from admin console', [
            'actor_id' => Auth::id(),
            'queue_item_id' => $id,
        ]);

        return back()->with('success', 'Item queued for retry');
    }
}
