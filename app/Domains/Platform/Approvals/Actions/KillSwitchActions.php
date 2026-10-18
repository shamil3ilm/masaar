<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use App\Domains\Platform\Services\KillSwitch;

/**
 * The kill switches: one stopped or resumed, or every one at once.
 *
 * Off as shipped, and better left so for stopping: an emergency stop that
 * waits for a second admin is not one. Resuming is where a policy earns its
 * keep.
 */
final class KillSwitchActions implements ApprovableActions
{
    public function __construct(
        private readonly KillSwitch $switches,
    ) {}

    public function keys(): array
    {
        return ['kill_switches.enable', 'kill_switches.disable', 'kill_switches.stop', 'kill_switches.resume'];
    }

    public function label(string $action): string
    {
        return match ($action) {
            'kill_switches.enable' => __('Stop one kill switch'),
            'kill_switches.disable' => __('Resume one kill switch'),
            'kill_switches.stop' => __('Emergency stop: every switch'),
            default => __('Resume every switch'),
        };
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::PlatformConfigure;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $switches = array_map(fn (array $switch): bool => (bool) $switch['enabled'], $this->switches->getStatus()['switches']);
        $switch = $payload['switch'] ?? null;

        return match ($action) {
            'kill_switches.enable' => new Preview('Stop '.$this->name((string) $switch).': '.$payload['reason'], 'KillSwitch', (string) $switch,
                [$switch => $switches[$switch] ?? false], [$switch => true, 'minutes' => $payload['minutes'] ?? null]),
            'kill_switches.disable' => new Preview('Resume '.$this->name((string) $switch), 'KillSwitch', (string) $switch,
                [$switch => $switches[$switch] ?? false], [$switch => false]),
            'kill_switches.stop' => new Preview('Stop every switch: '.$payload['reason'], 'KillSwitch', null,
                $switches, array_map(fn (bool $on): bool => true, $switches)),
            default => new Preview('Resume every switch', 'KillSwitch', null,
                $switches, array_map(fn (bool $on): bool => false, $switches)),
        };
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        // Who to record, in a form the next person can read: the email the
        // admin bar shows, not an id.
        $actor = (string) $requester->email;
        $switch = (string) ($payload['switch'] ?? '');

        match ($action) {
            'kill_switches.enable' => $this->switches->enable(
                $switch,
                reason: (string) $payload['reason'],
                enabledBy: $actor,
                durationSeconds: isset($payload['minutes']) ? (int) $payload['minutes'] * 60 : null,
            ),
            'kill_switches.disable' => $this->switches->disable($switch, disabledBy: $actor),
            'kill_switches.stop' => $this->switches->emergencyStop((string) $payload['reason'], $actor),
            default => $this->switches->resumeAll($actor),
        };

        return new ActionResult(match ($action) {
            'kill_switches.enable' => $this->name($switch).' stopped.',
            'kill_switches.disable' => $this->name($switch).' resumed.',
            'kill_switches.stop' => 'Every switch is on. Nothing is being issued, signed or filed.',
            default => 'Every switch is off. Normal operation has resumed.',
        });
    }

    private function name(string $switch): string
    {
        return ucfirst(str_replace('_', ' ', $switch));
    }
}
