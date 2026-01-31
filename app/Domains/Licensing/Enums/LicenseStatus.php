<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Enums;

/**
 * License status values.
 */
enum LicenseStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case PendingActivation = 'pending_activation';

    /**
     * Check if license is usable.
     */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Check if license can be reactivated.
     */
    public function canReactivate(): bool
    {
        return in_array($this, [self::Suspended, self::Expired], true);
    }

    /**
     * Get display label.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
            self::PendingActivation => 'Pending Activation',
        };
    }
}
