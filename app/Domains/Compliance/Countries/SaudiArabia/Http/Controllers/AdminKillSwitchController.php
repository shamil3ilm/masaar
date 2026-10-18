<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Services\KillSwitch;
use App\Http\Controllers\Controller;
use App\Support\OperatorError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * The kill switches, from a screen rather than a tinker prompt.
 *
 * KillSwitch has stopped issuance, submission, clearance, reporting and
 * signing since it was written, and the only way to reach it was
 * `php artisan tinker` on a production host - at the moment of an incident,
 * which is the worst time to be composing a method call with seven positional
 * arguments. The service already records who enabled a switch, when, why and
 * for how long; none of that was visible to the person who needed it.
 *
 * Every action is a POST behind a confirmation, because each one changes what
 * the platform will do for every taxpayer on it. The confirmation uses the
 * data-confirm attribute read by the bundled listener, not an inline handler,
 * which the content policy refuses.
 *
 * Switches are set globally here. KillSwitch also scopes to one tenant, and
 * that is deliberately not on this screen yet: it needs an organisation
 * picker and a clear answer to "which one wins", and a half-built scope
 * control on the page that stops invoicing is worse than none.
 *
 * Reachable only behind platform.admin, which checks users.is_platform_admin
 * and is not the per-organization admin role.
 */
class AdminKillSwitchController extends Controller
{
    /**
     * The longest a switch may be set for from this screen, in minutes.
     *
     * A day. The service caps duration itself and logs when it does, but a
     * form that accepts a year invites a typo nobody notices until invoicing
     * has been off for a year.
     */
    private const MAX_MINUTES = 1440;

    use HoldsForApproval;

    public function __construct(
        private readonly KillSwitch $switches,
        private readonly Approvals $approvals,
    ) {}

    public function index(): View
    {
        return view('admin.kill-switches.index', [
            'status' => $this->switches->getStatus(),
            'maxMinutes' => self::MAX_MINUTES,
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'switch' => ['required', 'string', Rule::in(KillSwitch::SWITCHES)],
            // Required by the service too, which throws without it. Asked for
            // here as well so the operator gets a field error rather than an
            // exception page.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_MINUTES],
        ]);

        return $this->attempt($request, 'kill_switches.enable', [
            'switch' => $validated['switch'],
            'reason' => $validated['reason'],
            'minutes' => isset($validated['minutes']) ? (int) $validated['minutes'] : null,
        ], $validated['reason']);
    }

    public function disable(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'switch' => ['required', 'string', Rule::in(KillSwitch::SWITCHES)],
        ]);

        return $this->attempt($request, 'kill_switches.disable', ['switch' => $validated['switch']]);
    }

    /**
     * Everything off, in one action.
     *
     * The reason is required here too. An emergency stop is the entry in the
     * log that someone will be asked about afterwards.
     */
    public function stop(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return $this->attempt($request, 'kill_switches.stop', ['reason' => $validated['reason']], $validated['reason']);
    }

    public function resume(Request $request): RedirectResponse
    {
        return $this->attempt($request, 'kill_switches.resume', []);
    }

    /**
     * Run the action and say what happened, either way.
     *
     * KillSwitch throws InvalidArgumentException for a switch it does not know
     * and for a missing reason, and the lock around the registry can time out.
     * The exception renderer in bootstrap/app.php is registered for api/* and
     * v1/* only, so without this an operator mid-incident would get an error
     * page instead of a sentence.
     */
    private function attempt(Request $request, string $action, array $payload, ?string $reason = null): RedirectResponse
    {
        try {
            return $this->holdOrRun($this->approvals, $request, $action, $payload,
                fn (ActionResult $done): RedirectResponse => redirect()->route('admin.kill-switches')->with('success', $done->message),
                $reason);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            // KillSwitch's own refusals are sentences; anything else - the
            // registry lock timing out, the store failing - is logged under
            // a reference rather than shown.
            return redirect()->route('admin.kill-switches')
                ->with('error', 'That did not take effect: '.OperatorError::message(
                    $e,
                    'Changing the switch',
                    [\InvalidArgumentException::class],
                ));
        }
    }
}
