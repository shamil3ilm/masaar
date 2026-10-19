<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Http\Controllers;

use App\Domains\Compliance\Networks\Peppol\Reports\NetworkOverview;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentHistory;
use App\Domains\Compliance\Networks\Peppol\Sending\PeppolSender;
use App\Domains\Compliance\Networks\Peppol\Sending\ProviderOutage;
use App\Domains\Compliance\Networks\Peppol\Sending\ReportRetrier;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The admin console's Peppol page: the gateway, documents per state, and
 * the ones waiting on someone - with Resend for a failed one, as
 * peppol:resend does from the command line, and Retry report for one whose
 * report to the authority failed, as peppol:retry-reports does.
 */
class AdminPeppolController extends Controller
{
    public function __construct(
        private readonly NetworkOverview $overview,
        private readonly ProviderOutage $outage,
    ) {}

    /**
     * GET /admin/peppol
     */
    public function index(): View
    {
        return view('networks.peppol.admin', [
            'gateway' => $this->overview->gateway(),
            'outage' => $this->outage->current(),
            'counts' => $this->overview->counts(),
            'waiting' => $this->overview->waiting(),
            'latest' => $this->overview->latest(),
        ]);
    }

    /**
     * POST /admin/peppol/{id}/resend
     */
    public function resend(string $id, PeppolSender $sender): RedirectResponse
    {
        if (! $sender->resend($id)) {
            return back()->with('error', 'Only a document that failed to send can be sent again.');
        }

        return back()->with('success', 'The document is queued to be sent again.');
    }

    /**
     * POST /admin/peppol/{id}/retry-report
     */
    public function retryReport(string $id, ReportRetrier $retrier): RedirectResponse
    {
        $refusal = $retrier->retry($id, DocumentHistory::SOURCE_OPERATOR);

        if ($refusal !== null) {
            return back()->with('error', $refusal);
        }

        return back()->with('success', 'The service provider was asked to report the document again.');
    }
}
