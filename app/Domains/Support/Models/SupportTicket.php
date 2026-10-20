<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Models\Organization;
use App\Domains\Support\Enums\RequestStage;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketKind;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A customer asking for help, for paid work (a customization) or for
 * something new (a feature request), and the conversation that follows.
 */
class SupportTicket extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'number',
        'org_id',
        'kind',
        'opened_by',
        'subject',
        'category',
        'priority',
        'status',
        'stage',
        'assigned_to',
        'last_activity_at',
        'resolved_at',
    ];

    /**
     * Who opened it is who created it.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'opened_by', 'updated_by' => 'updated_by'];
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'kind' => TicketKind::class,
            'stage' => RequestStage::class,
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
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    /** A feature request's category, system and importance. */
    public function featureRequest(): HasOne
    {
        return $this->hasOne(FeatureRequest::class, 'ticket_id');
    }

    /** A customization's agreed work, price and features. */
    public function customization(): HasOne
    {
        return $this->hasOne(PaidCustomization::class, 'ticket_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->orderBy('created_at');
    }
}
