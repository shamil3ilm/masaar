<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use DomainException;

/**
 * Switching an organization's ZATCA environment to or from production, as
 * an admin action an approval policy holds - held as shipped, so one admin
 * alone cannot move a taxpayer onto the live gateway, or off it. A switch
 * between the two test environments is not held (AdminEnvironmentController).
 *
 * Added to the catalogue by the module's service provider.
 */
final class EnvironmentSwitchActions implements ApprovableActions
{
    public const PRODUCTION = 'zatca_environment.production';

    public function __construct(
        private readonly EnvironmentSwitch $switch,
    ) {}

    /** Whether a switch between these two is held: either is production. */
    public static function holds(string $from, string $to): bool
    {
        return $from !== $to && in_array(FatooraEnvironment::PRODUCTION, [$from, $to], true);
    }

    public function keys(): array
    {
        return [self::PRODUCTION];
    }

    public function label(string $action): string
    {
        return __('Switch an organization\'s ZATCA environment to or from production');
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::PlatformConfigure;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $organization = $this->organization($payload);
        $from = FatooraEnvironment::of($organization);

        return new Preview(
            'Switch the ZATCA environment of '.$organization->name.' from '.$from.' to '.$payload['environment'],
            'Organization', (string) $organization->id,
            ['environment' => $from],
            ['environment' => (string) $payload['environment']],
        );
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        // Who asked for it, whoever approved it.
        $this->switch->set($this->organization($payload), (string) $payload['environment'], (string) $requester->id);

        return new ActionResult('ZATCA environment saved.');
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws DomainException when the organization is gone
     */
    private function organization(array $payload): Organization
    {
        return $this->switch->organization((string) $payload['organization_id'])
            ?? throw new DomainException('That organization no longer exists.');
    }
}
