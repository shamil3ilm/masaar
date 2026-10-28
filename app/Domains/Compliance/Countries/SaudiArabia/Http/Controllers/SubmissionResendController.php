<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Support\Errors\ComplianceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sending a failed submission again, by hand: from the portal by the
 * organization's administrators, or from the console by a platform admin
 * who runs operations. Only a failure is sent again (InvoiceSubmission::
 * isResendable); every check runs first; each attempt is on the
 * organization's activity record.
 */
class SubmissionResendController extends Controller
{
    public function __construct(
        private readonly SubmissionTracker $tracker,
        private readonly AuditService $audit,
        private readonly TenantResolver $tenant,
    ) {}

    public function portal(Request $request, string $submissionId): RedirectResponse
    {
        $organizationId = (string) $request->attributes->get(PortalTenant::ORG_ID);

        abort_unless(MemberRole::administers($request->user()?->roleIn($organizationId)), 403, __('Only an administrator of this organization can send a filing again.'));

        // The portal's own tenant (PortalTenant): its scope is this filter.
        $submission = InvoiceSubmission::query()
            ->where('org_id', $organizationId)
            ->findOrFail($submissionId);

        return $this->resend($submission, 'portal');
    }

    public function console(string $submissionId): RedirectResponse
    {
        $submission = InvoiceSubmission::acrossTenants()->findOrFail($submissionId);

        // As its organization: the console has no tenant of its own, and
        // every check reads the organization's records.
        return $this->tenant->runAs((string) $submission->org_id, fn (): RedirectResponse => $this->resend($submission, 'console'));
    }

    private function resend(InvoiceSubmission $submission, string $from): RedirectResponse
    {
        try {
            $result = $this->tracker->resend($submission);
        } catch (ComplianceException $e) {
            return back()->with('error', __('It was not sent again: :reason', ['reason' => $e->getMessage()]));
        }

        $submission->refresh();

        $this->audit->logFor((string) $submission->org_id, 'zatca.submission.resent', $submission, [
            'invoice_id' => $submission->invoice_id,
            'from' => $from,
            'state' => $submission->state,
        ]);

        $error = $result['errors'][0] ?? $submission->last_error ?? '';

        return match (true) {
            (bool) ($result['success'] ?? false) => back()->with('success', __('Sent again, and ZATCA accepted it.')),
            $submission->state === 'rejected' => back()->with('error', __('Sent again, and ZATCA rejected it: :error A rejected invoice is not sent again. Issue a corrected invoice instead.', ['error' => $error])),
            default => back()->with('error', __('Sent again, and it did not get through: :error', ['error' => $error])),
        };
    }
}
