<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Models;

use App\Domains\Auth\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * License Registration Audit Model.
 *
 * Tracks all changes to license registrations for compliance and audit purposes.
 *
 * @property string $id
 * @property string $registration_id
 * @property string|null $user_id
 * @property string $action
 * @property string|null $old_status
 * @property string|null $new_status
 * @property array|null $changes
 * @property string|null $reason
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class LicenseRegistrationAudit extends Model
{
    use HasUuids;

    protected $fillable = [
        'registration_id',
        'user_id',
        'action',
        'old_status',
        'new_status',
        'changes',
        'reason',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    // Action constants
    public const ACTION_CREATED = 'created';

    public const ACTION_APPROVED = 'approved';

    public const ACTION_REJECTED = 'rejected';

    public const ACTION_SUSPENDED = 'suspended';

    public const ACTION_REVOKED = 'revoked';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_VERIFIED = 'verified';

    /**
     * Get the registration this audit belongs to.
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(LicenseRegistration::class, 'registration_id');
    }

    /**
     * Get the user who performed the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
