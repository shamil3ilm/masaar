<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;

/**
 * Switching a country on or off: whether the platform issues there at all.
 */
final class CountryActions implements ApprovableActions
{
    public function __construct(
        private readonly JurisdictionRegistry $registry,
        private readonly JurisdictionAvailability $availability,
    ) {}

    public function keys(): array
    {
        return ['countries.switch'];
    }

    public function label(string $action): string
    {
        return __('Switch a country on or off');
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::PlatformConfigure;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $jurisdiction = $this->registry->get((string) $payload['country']);
        $enabled = (bool) $payload['enabled'];

        return new Preview(
            ($enabled ? 'Switch on: ' : 'Switch off: ').$jurisdiction->name(),
            'Jurisdiction', $jurisdiction->code(),
            ['enabled' => $jurisdiction->enabled()],
            ['enabled' => $enabled],
        );
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        $jurisdiction = $this->registry->get((string) $payload['country']);
        $enabled = (bool) $payload['enabled'];

        // Who asked for it, whoever approved it.
        $this->availability->set($jurisdiction->code(), $enabled, (string) $requester->id);

        return new ActionResult($enabled
            ? $jurisdiction->name().' is switched on. Its organizations can issue again.'
            : $jurisdiction->name().' is switched off. Nothing new is issued there; what was already issued keeps being filed.');
    }
}
