<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Services\AuditReport;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit trail, read-only.
 *
 * Nothing on this screen changes a record. An audit trail an admin can edit
 * from the console is not one.
 */
class AdminAuditController extends Controller
{
    public function __construct(
        private readonly AuditReport $audit,
        private readonly Lists $lists,
    ) {}

    public function index(Request $request): View
    {
        $list = $this->lists->admin($this->audit->spec('admin.audit'), $request, 'admin.audit.export');

        return view('admin.audit', [
            'entries' => $this->audit->page($list),
            'list' => $list,
        ]);
    }

    /** Every entry the list's filters give, as a CSV. */
    public function export(Request $request): StreamedResponse
    {
        return $this->audit->csv($this->lists->admin($this->audit->spec('admin.audit'), $request), 'audit-log-'.now()->format('Y-m-d').'.csv');
    }
}
