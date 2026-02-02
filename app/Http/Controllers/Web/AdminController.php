<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Web Admin Dashboard Controller.
 *
 * Provides Blade-based admin views for CompliPay internal use.
 * Uses API endpoints for data to keep logic DRY.
 */
class AdminController extends Controller
{
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
        $organizations = DB::table('organizations')
            ->leftJoin('certificate_lineage', function ($join) {
                $join->on('organizations.id', '=', 'certificate_lineage.organization_id')
                    ->where('certificate_lineage.status', '=', 'active');
            })
            ->select([
                'organizations.id',
                'organizations.name',
                'organizations.vat_number',
                'organizations.status',
                'organizations.created_at',
                'certificate_lineage.valid_to as cert_expires_at',
            ])
            ->orderByDesc('organizations.created_at')
            ->paginate(20);

        // Get submission stats per org
        $orgIds = $organizations->pluck('id');
        $submissionStats = DB::table('invoice_submissions')
            ->whereIn('organization_id', $orgIds)
            ->selectRaw('organization_id, COUNT(*) as total, SUM(CASE WHEN state IN ("cleared", "reported") THEN 1 ELSE 0 END) as successful')
            ->groupBy('organization_id')
            ->pluck('successful', 'organization_id');

        return view('admin.organizations', compact('organizations', 'submissionStats'));
    }

    /**
     * Organization detail view.
     */
    public function organizationDetail(string $id): View
    {
        $organization = DB::table('organizations')->where('id', $id)->first();

        if (!$organization) {
            abort(404);
        }

        // Optimized: Single query for invoice count
        $invoiceCount = DB::table('invoices')->where('organization_id', $id)->count();

        // Optimized: Single query for all submission stats
        $submissionStats = DB::table('invoice_submissions')
            ->where('organization_id', $id)
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
            ->where('organization_id', $id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $certificate = DB::table('certificate_lineage')
            ->where('organization_id', $id)
            ->where('status', 'active')
            ->first();

        return view('admin.organization-detail', compact('organization', 'stats', 'recentSubmissions', 'certificate'));
    }

    /**
     * Offline queue view.
     */
    public function queue(Request $request): View
    {
        $state = $request->query('state');
        $orgId = $request->query('organization_id');

        $query = DB::table('offline_queue')
            ->leftJoin('organizations', 'offline_queue.organization_id', '=', 'organizations.id')
            ->select([
                'offline_queue.*',
                'organizations.name as organization_name',
            ])
            ->orderByDesc('offline_queue.queued_at');

        if ($state) {
            $query->where('offline_queue.state', $state);
        }

        if ($orgId) {
            $query->where('offline_queue.organization_id', $orgId);
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
        $orgId = $request->query('organization_id');

        $query = DB::table('invoice_submissions')
            ->leftJoin('organizations', 'invoice_submissions.organization_id', '=', 'organizations.id')
            ->select([
                'invoice_submissions.*',
                'organizations.name as organization_name',
            ])
            ->orderByDesc('invoice_submissions.created_at');

        if ($state) {
            $query->where('invoice_submissions.state', $state);
        }

        if ($orgId) {
            $query->where('invoice_submissions.organization_id', $orgId);
        }

        $logs = $query->paginate(50);

        $stateCounts = DB::table('invoice_submissions')
            ->selectRaw('state, COUNT(*) as count')
            ->groupBy('state')
            ->pluck('count', 'state');

        return view('admin.logs', compact('logs', 'stateCounts', 'state', 'orgId'));
    }
}
