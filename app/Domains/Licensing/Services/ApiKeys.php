<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Enums\ApiScope;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Licensing\Models\ApiKey;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Models\ApprovalRequest;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An organization's own API keys: made and revoked by its administrators in
 * the portal, used by its systems on the partner API.
 *
 * A key acts under the organization's licence, so making one asks Access
 * whether the organization has API access at all (a licence, its country,
 * its status), and its scopes are a choice from the licence's. A production
 * key waits for a platform admin when the approval policy
 * api_keys.production is on; a sandbox key never files anything live
 * (SandboxGuard), so it never waits.
 *
 * The secret exists in plain text only in what create() returns. It is kept
 * as its peppered hash, and it is never logged.
 */
class ApiKeys
{
    /** The approval policy a production key waits under. */
    public const APPROVAL = 'api_keys.production';

    public function __construct(
        private readonly Access $access,
        private readonly LicenseValidationService $licences,
        private readonly Approvals $approvals,
        private readonly UsageMeteringService $metering,
    ) {}

    /**
     * The organization's keys, newest first, each with its usage.
     *
     * @return list<array{key: ApiKey, today: int, today_errors: int, month: int, month_errors: int, approval: ApprovalRequest|null}>
     */
    public function list(string $organizationId): array
    {
        $keys = $this->keys($organizationId);
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $usage = DB::table('api_key_usage')
            ->whereIn('api_key_id', $keys->modelKeys())
            ->where('usage_date', '>=', $monthStart)
            ->get(['api_key_id', 'usage_date', 'requests', 'errors'])
            ->groupBy('api_key_id');

        $approvals = $this->approvals->requests($keys->pluck('approval_request_id')->filter()->values()->all());

        return $keys->map(function (ApiKey $key) use ($usage, $today, $approvals): array {
            $rows = $usage->get($key->id, collect());
            $todays = $rows->filter(fn (object $row): bool => str_starts_with((string) $row->usage_date, $today));

            return [
                'key' => $key,
                'today' => (int) $todays->sum('requests'),
                'today_errors' => (int) $todays->sum('errors'),
                'month' => (int) $rows->sum('requests'),
                'month_errors' => (int) $rows->sum('errors'),
                'approval' => $key->approval_request_id === null ? null : $approvals->get($key->approval_request_id),
            ];
        })->values()->all();
    }

    /**
     * The scopes a key of this organization may be given: its licences'
     * scopes, with the ones they imply. None without a licence.
     *
     * @return list<string> in the catalogue's order
     */
    public function offeredScopes(string $organizationId): array
    {
        $held = $this->licences->organizationLicences($organizationId)
            ->flatMap(fn (License $licence): array => ApiScope::expandScopes((array) $licence->scopes))
            ->unique()
            ->all();

        return array_values(array_filter(
            array_map(fn (ApiScope $scope): string => $scope->value, ApiScope::cases()),
            fn (string $scope): bool => in_array($scope, $held, true),
        ));
    }

    /**
     * What every key of the organization draws on together: the licence's
     * allowances this month and today, as the platform admin set them for
     * this customer. Null without a licence.
     *
     * @return array{invoices: int, invoices_limit: int|null, calls_today: int, calls_limit: int|null}|null
     */
    public function allowance(string $organizationId): ?array
    {
        $licence = $this->licences->meteringLicence($organizationId);

        if ($licence === null) {
            return null;
        }

        $summary = $this->metering->getUsageSummary($licence);
        $today = now()->toDateString();
        $todays = collect($summary['daily_breakdown'])->firstWhere('date', $today);

        return [
            'invoices' => (int) $summary['totals']['invoices_submitted'],
            'invoices_limit' => $licence->isUnlimited('invoices_per_month') ? null : (int) $licence->invoices_per_month,
            'calls_today' => (int) ($todays['api_calls'] ?? 0),
            'calls_limit' => $licence->isUnlimited('calls_per_day') ? null : (int) $licence->calls_per_day,
        ];
    }

    /**
     * Why the organization's administrators cannot make a key now, or null.
     */
    public function refusal(Organization $organization, User $user): ?string
    {
        $decision = $this->access->decide($organization, $user, Feature::ApiAccess);

        return $decision->allowed ? null : $decision->reason;
    }

    /**
     * Make a key and return its secret, the only time it is ever readable.
     *
     * @param  array{name: string, environment: string, scopes: list<string>, expires_at?: string|null, allowed_ips?: string|null}  $data
     * @return array{key: ApiKey, secret: string, waits: bool}
     *
     * @throws DomainException when the organization may not make one now
     * @throws ValidationException for a scope, an address or a missing approval reason
     */
    public function create(Organization $organization, User $user, array $data, ?string $reason = null): array
    {
        $organizationId = (string) $organization->id;
        $refusal = $this->refusal($organization, $user);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        if ($this->keys($organizationId)->count() >= (int) config('licensing.api_keys.max_per_organization')) {
            throw new DomainException(__('This organization already has the most API keys it may hold. Revoke one it no longer uses first.'));
        }

        $throttle = 'api-keys:create:'.$organizationId;

        if (RateLimiter::tooManyAttempts($throttle, (int) config('licensing.api_keys.created_per_hour'))) {
            throw new DomainException(__('Too many API keys made in the last hour. Try again later.'));
        }

        $scopes = $this->scopes($organizationId, $data['scopes']);
        $addresses = $this->addresses($data['allowed_ips'] ?? null);
        $environment = LicenseEnvironment::from($data['environment']);
        $secret = License::generateApiSecret();

        $made = DB::transaction(function () use ($organizationId, $data, $scopes, $addresses, $environment, $secret, $user, $reason): array {
            $key = new ApiKey([
                'name' => $data['name'],
                'environment' => $environment,
                'scopes' => $scopes,
                'allowed_ips' => $addresses === [] ? null : $addresses,
                'expires_at' => $data['expires_at'] ?? null,
            ]);
            $key->forceFill([
                'org_id' => $organizationId,
                'api_key' => License::generateApiKey($environment),
                'secret_hash' => License::hashSecret($secret),
                'status' => $environment === LicenseEnvironment::Production ? ApiKey::PENDING : ApiKey::ACTIVE,
            ])->save();

            if ($environment !== LicenseEnvironment::Production) {
                return [$key, false];
            }

            // Done now when the policy is off, or when no platform admin
            // could approve it; otherwise a request with the reason given.
            $outcome = $this->approvals->submit(self::APPROVAL, ['api_key_id' => (string) $key->id], $user, $reason);

            if ($outcome->waits()) {
                $key->forceFill(['approval_request_id' => $outcome->request?->id])->save();
            }

            return [$key->fresh() ?? $key, $outcome->waits()];
        });

        RateLimiter::hit($throttle, 3600);

        return ['key' => $made[0], 'secret' => $secret, 'waits' => $made[1]];
    }

    /**
     * A waiting production key put to use: what an approved request does.
     *
     * @throws DomainException when the key is gone
     */
    public function activate(string $keyId): ApiKey
    {
        $key = $this->byId($keyId) ?? throw new DomainException(__('That API key has been revoked.'));

        $key->forceFill(['status' => ApiKey::ACTIVE])->save();

        return $key;
    }

    /**
     * A key not yet revoked, whichever organization's: for the platform
     * admin deciding on it, who holds no tenant.
     */
    public function byId(string $keyId): ?ApiKey
    {
        return ApiKey::acrossTenants()->find($keyId);
    }

    /**
     * The key, when it is one of the organization's and not yet revoked.
     */
    public function find(string $organizationId, string $keyId): ?ApiKey
    {
        return ApiKey::acrossTenants()
            ->where('org_id', $organizationId)
            ->find($keyId);
    }

    /**
     * Revoke a key: it stops at once and cannot be brought back.
     */
    public function revoke(ApiKey $key): void
    {
        $key->delete();
    }

    /**
     * Count a call made with the key, for its usage on the page.
     */
    public function recordUse(ApiKey $key, bool $failed): void
    {
        $now = now();
        $date = $now->toDateString();

        DB::table('api_key_usage')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'api_key_id' => $key->id,
            'usage_date' => $date,
            'requests' => 0,
            'errors' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('api_key_usage')
            ->where(['api_key_id' => $key->id, 'usage_date' => $date])
            ->update([
                'requests' => DB::raw('requests + 1'),
                'errors' => DB::raw('errors + '.($failed ? 1 : 0)),
                'updated_at' => $now,
            ]);

        ApiKey::acrossTenants()->whereKey($key->id)->update(['last_used_at' => $now]);
    }

    /**
     * @return Collection<int, ApiKey>
     */
    private function keys(string $organizationId): Collection
    {
        return ApiKey::acrossTenants()
            ->where('org_id', $organizationId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The scopes asked for, each one the licence holds.
     *
     * @param  list<string>  $asked
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function scopes(string $organizationId, array $asked): array
    {
        $offered = $this->offeredScopes($organizationId);
        $refused = array_values(array_diff($asked, $offered));

        if ($asked === [] || $refused !== []) {
            throw ValidationException::withMessages(['scopes' => $asked === []
                ? __('Choose at least one thing the key may do.')
                : __('Your plan does not include :scopes.', ['scopes' => implode(', ', $refused)])]);
        }

        return array_values(array_unique($asked));
    }

    /**
     * The allowlist as typed - addresses or CIDR ranges, one a line or
     * separated by commas - each checked.
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function addresses(?string $typed): array
    {
        $entries = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $typed) ?: []))));

        foreach ($entries as $entry) {
            if (! self::isAddressOrRange($entry)) {
                throw ValidationException::withMessages(['allowed_ips' => __(':entry is not an IP address or a CIDR range.', ['entry' => $entry])]);
            }
        }

        if (count($entries) > 50) {
            throw ValidationException::withMessages(['allowed_ips' => __('Name at most 50 addresses or ranges.')]);
        }

        return $entries;
    }

    private static function isAddressOrRange(string $entry): bool
    {
        [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $max = str_contains((string) $address, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= $max;
    }
}
