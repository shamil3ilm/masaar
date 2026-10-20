<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Models;

use App\Domains\Licensing\Enums\ApiScope;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * An API key an organization's administrator made in the portal.
 *
 * It acts under the organization's licence (ValidateLicense puts that
 * licence on the request, and this key beside it): the licence decides the
 * features and the allowances, the key narrows the scopes and says where it
 * may be used from and until when. Revoking it is removing it.
 *
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property LicenseEnvironment $environment
 * @property string $api_key
 * @property string $secret_hash
 * @property list<string> $scopes
 * @property list<string>|null $allowed_ips
 * @property Carbon|null $expires_at
 * @property string $status
 * @property string|null $approval_request_id
 * @property Carbon|null $last_used_at
 */
class ApiKey extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    /** The request attribute ValidateLicense carries the key under. */
    public const ATTRIBUTE = 'api_key';

    public const ACTIVE = 'active';

    /** A production key waiting for a platform admin's approval. */
    public const PENDING = 'pending';

    protected $fillable = [
        'name',
        'environment',
        'scopes',
        'allowed_ips',
        'expires_at',
    ];

    protected $hidden = [
        'secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'environment' => LicenseEnvironment::class,
            'scopes' => 'array',
            'allowed_ips' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * The secret matches, compared in time independent of where it differs.
     * Hashed as a licence's is, with the platform's pepper.
     */
    public function verifySecret(string $secret): bool
    {
        return hash_equals((string) $this->secret_hash, License::hashSecret($secret));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * The address may use the key: any, when no list is set.
     */
    public function allowsAddress(?string $address): bool
    {
        $allowed = array_values(array_filter((array) $this->allowed_ips));

        if ($allowed === []) {
            return true;
        }

        return $address !== null && IpUtils::checkIp($address, $allowed);
    }

    /**
     * The key was given the scope, or one that implies it.
     */
    public function hasScope(string|ApiScope $scope): bool
    {
        $value = $scope instanceof ApiScope ? $scope->value : $scope;

        return in_array($value, ApiScope::expandScopes((array) $this->scopes), true);
    }
}
