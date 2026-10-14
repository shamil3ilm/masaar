<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function __construct(private readonly Settings $settings) {}

    public function index(): View
    {
        return view('admin.settings', [
            'definitions' => Settings::DEFINITIONS,
            'settings' => $this->settings,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // The email, not the id. This is read by someone asking who changed a
        // threshold, and a UUID does not answer that - the same reason the
        // kill switches record one.
        $problems = $this->settings->put($request->all(), Auth::user()?->email);

        if ($problems !== []) {
            return redirect()
                ->route('admin.settings')
                ->with('error', implode(' ', $problems))
                ->withInput();
        }

        return redirect()->route('admin.settings')->with('success', 'Settings saved.');
    }
}
