<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Models\Organization;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer asking for help, and the conversation that follows.
 */
class SupportTicket extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'number',
        'org_id',
        'opened_by',
        'subject',
        'category',
        'priority',
        'status',
        'assigned_to',
        'last_activity_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'category' => TicketCategory::class,
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'last_activity_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** What a customer quotes: T-000042. */
    public function reference(): string
    {
        return 'T-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->orderBy('created_at');
    }
}
