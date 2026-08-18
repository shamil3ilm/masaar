<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceProfile extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUS_PENDING = 'pending_onboarding';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'organization_id',
        'jurisdiction',
        'engine',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Get a typed setting value with an optional default.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
