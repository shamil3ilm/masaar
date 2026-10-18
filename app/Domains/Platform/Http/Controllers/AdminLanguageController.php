<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Organization\Services\LanguageGrace;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Services\ArabicNameReport;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Where an organization's country requires Arabic on its invoices: the
 * names it still lacks, and a platform admin's grace period - warn instead
 * of refuse until a date - for a customer filling them in.
 *
 * The grace takes a reason, is stored with who set it (LanguageGrace) and
 * is audited with the state before and after (AuditAdminAction). It ends
 * on its own; withdrawing it early is setting no date.
 */
class AdminLanguageController extends Controller
{
    public function __construct(
        private readonly ArabicNameReport $report,
        private readonly LanguageGrace $grace,
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

        // The email, not the id: read by someone asking who granted it, as
        // the feature switches record it.
        $applied = $this->grace->set($organization, $until, (string) $validated['reason'], Auth::user()?->email);

        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);
        $request->attributes->set(AuditAdminAction::CHANGES, [
            ['language_grace_until' => $before['until'] ?? null],
            ['language_grace_until' => $until?->toDateString(), 'reason' => (string) $validated['reason']],
        ]);

        return redirect()->route('admin.organization.detail', $id)->with('success', match (true) {
            ! $applied => 'The grace period is waiting for approval.',
            $until === null => 'Missing Arabic text is refused again for this organization.',
            default => 'Missing Arabic text only warns for this organization until '.$until->toDateString().'.',
        });
    }
}
