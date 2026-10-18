<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Models\FeatureOverride;
use App\Domains\Platform\Services\OrganizationReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A platform admin's switch for one feature of one organization: off whatever
 * its plan says, on beyond its plan, or back to the default.
 *
 * Only features whose every path honours the switch can be switched
 * (Feature::canSwitchOff, Feature::canGrantBeyondPlan), so the console never
 * offers a control that changes the answer and not the behaviour. A reason
 * is required, stored with who set it, and the change is audited with the
 * state before and after (AuditAdminAction).
 */
class AdminFeatureController extends Controller
{
    use HoldsForApproval;

    private const DEFAULT = 'default';

    public function __construct(
        private readonly OrganizationReport $organizations,
        private readonly FeatureOverrides $overrides,
        private readonly Approvals $approvals,
    ) {}

    public function update(Request $request, string $id): RedirectResponse
    {
        if ($this->organizations->find($id) === null) {
            abort(404);
        }

        $validated = $request->validate([
            'feature' => ['required', 'string', Rule::in(array_map(
                fn (Feature $feature): string => $feature->value,
                array_filter(Feature::cases(), fn (Feature $feature): bool => $feature->overridable()),
            ))],
            'state' => ['required', 'string', Rule::in([self::DEFAULT, FeatureOverride::OFF, FeatureOverride::ON])],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $feature = Feature::from((string) $validated['feature']);
        $state = (string) $validated['state'];

        if (($state === FeatureOverride::OFF && ! $feature->canSwitchOff())
            || ($state === FeatureOverride::ON && ! $feature->canGrantBeyondPlan())) {
            throw ValidationException::withMessages(['state' => $feature->label().' cannot be set '.$state.' for one organization.']);
        }

        $before = $this->overrides->state($id, $feature) ?? self::DEFAULT;

        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);
        $request->attributes->set(AuditAdminAction::CHANGES, [
            ['feature' => $feature->value, 'state' => $before],
            ['feature' => $feature->value, 'state' => $state, 'reason' => (string) $validated['reason']],
        ]);

        return $this->holdOrRun($this->approvals, $request, 'features.override', [
            'organization_id' => $id,
            'feature' => $feature->value,
            'state' => $state,
            'reason' => (string) $validated['reason'],
        ], fn (ActionResult $done): RedirectResponse => redirect()->route('admin.organization.detail', $id)->with('success', $done->message),
            (string) $validated['reason']);
    }
}
