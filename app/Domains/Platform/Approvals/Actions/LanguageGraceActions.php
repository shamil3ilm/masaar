<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\LanguageGrace;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Granting or extending an organization's language grace: until the date,
 * an invoice missing the Arabic its country requires is drafted with a
 * warning instead of refused (LanguageGrace). Off by default, like the
 * other actions that change no one's access; withdrawing a grace is never
 * held back.
 */
final class LanguageGraceActions implements ApprovableActions
{
    public const GRANT = 'organizations.language_grace';

    public function __construct(
        private readonly LanguageGrace $grace,
    ) {}

    public function keys(): array
    {
        return [self::GRANT];
    }

    public function label(string $action): string
    {
        return __('Grant or extend a language grace period');
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::FeaturesManage;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $organization = $this->organization($payload);

        return new Preview(
            'Language grace for '.$organization->name.' until '.$payload['until'],
            'Organization', (string) $organization->id,
            ['language_grace_until' => $this->grace->until($organization)?->toDateString()],
            ['language_grace_until' => (string) $payload['until'], 'reason' => (string) $payload['reason']],
        );
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        $organization = $this->organization($payload);
        $until = CarbonImmutable::parse((string) $payload['until'])->startOfDay();

        // Who asked for it, whoever approved it.
        $this->grace->set($organization, $until, (string) $payload['reason'], (string) $requester->id);

        return new ActionResult('Missing Arabic text only warns for this organization until '.$until->toDateString().'.');
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws DomainException when the organization is gone
     */
    private function organization(array $payload): Organization
    {
        return Organization::query()->find((string) $payload['organization_id'])
            ?? throw new DomainException('That organization no longer exists.');
    }
}
