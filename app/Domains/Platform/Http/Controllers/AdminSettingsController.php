<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The operational numbers, from a screen rather than a release.
 *
 * The thresholds that decide when the dashboard calls something a problem
 * were private consts in IssueDetector, and the certificate warning schedule
 * was a literal in config with its channels in an environment variable. Every
 * one of them is a value whose right setting is only learned by watching the
 * platform run, and none of them could be changed without shipping.
 *
 * Validation lives in the Settings service rather than a FormRequest,
 * because one of the rules compares two settings - a critical threshold below
 * its warning threshold inverts the severity logic silently - and that has to
 * be checked against the rest of the submission, not against the stored value.
 */
class AdminSettingsController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Customizations $customizations,
    ) {}

    public function index(): View
    {
        return view('admin.settings', [
            'definitions' => Settings::DEFINITIONS,
            'settings' => $this->settings,
            'customizations' => $this->customizations->describe(Level::Platform, Scope::platform()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $before = $this->settings->all();
        $problems = $this->settings->put($request->all());

        // Only what changed, so the record answers "what did they change"
        // rather than listing every threshold on every save.
        if ($problems === []) {
            $after = $this->settings->all();
            $changed = array_keys(array_filter($after, fn (mixed $value, string $key): bool => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

            $request->attributes->set(AuditAdminAction::CHANGES, [
                array_intersect_key($before, array_flip($changed)),
                array_intersect_key($after, array_flip($changed)),
            ]);
        }

        if ($problems !== []) {
            return redirect()
                ->route('admin.settings')
                ->with('error', implode(' ', $problems))
                ->withInput();
        }

        return redirect()->route('admin.settings')->with('success', 'Settings saved.');
    }
}
