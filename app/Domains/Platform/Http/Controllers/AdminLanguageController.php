<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Organization\Services\LanguageGrace;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Actions\LanguageGraceActions;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Services\ArabicNameReport;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Where an organization's country requires Arabic on its invoices: the
 * names it still lacks, and a platform admin's grace period - warn instead
 * of refuse until a date - for a customer filling them in.
 *
 * The grace takes a reason, is stored with who set it (LanguageGrace) and
 * is audited with the state before and after (AuditAdminAction). It ends
 * on its own; withdrawing it early is setting no date. Granting or
 * extending it waits for a second admin when its approval policy is on
 * (LanguageGraceActions); withdrawing it never does.
 */
class AdminLanguageController extends Controller
{
    use HoldsForApproval;

    public function __construct(
        private readonly ArabicNameReport $report,
        private readonly LanguageGrace $grace,
        private readonly Approvals $approvals,
    ) {}

    /** The organization's own Arabic name, and its customers' and products', that are missing. */
    public function missing(string $id): View
    {
        $missing = $this->report->missing($id) ?? abort(404);

        return view('admin.missing-arabic', $missing);
    }

    public function grace(Request $request, string $id): RedirectResponse
    {
        $missing = $this->report->missing($id) ?? abort(404);
        $organization = $missing['organization'];

        $validated = $request->validate([
            'until' => ['nullable', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $until = filled($validated['until'] ?? null) ? CarbonImmutable::parse((string) $validated['until'])->startOfDay() : null;
        $before = $this->grace->describe($organization);

        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);
        $request->attributes->set(AuditAdminAction::CHANGES, [
            ['language_grace_until' => $before['until'] ?? null],
            ['language_grace_until' => $until?->toDateString(), 'reason' => (string) $validated['reason']],
        ]);

        if ($until === null) {
            $this->grace->set($organization, null, (string) $validated['reason']);

            return redirect()->route('admin.organization.detail', $id)->with('success', 'Missing Arabic text is refused again for this organization.');
        }

        return $this->holdOrRun($this->approvals, $request, LanguageGraceActions::GRANT,
            ['organization_id' => (string) $organization->id, 'until' => $until->toDateString(), 'reason' => (string) $validated['reason']],
            fn (ActionResult $done): RedirectResponse => redirect()->route('admin.organization.detail', $id)->with('success', $done->message),
            (string) $validated['reason']);
    }
}
