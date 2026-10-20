<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use App\Domains\Organization\Concerns\TenantScope;
use App\Domains\Support\Enums\RequestImportance;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a feature request asks for, beside its ticket. Its category is a key
 * of the admin-edited list (RequestCategories).
 *
 * @property string $ticket_id
 * @property string $category
 * @property string|null $system_name
 * @property RequestImportance $importance
 */
class FeatureRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'ticket_id',
        'category',
        'system_name',
        'importance',
    ];

    protected function casts(): array
    {
        return ['importance' => RequestImportance::class];
    }

    public function ticket(): BelongsTo
    {
        // The row names its ticket: the tenant scope, which a platform admin
        // holds no tenant for, has nothing to add.
        return $this->belongsTo(SupportTicket::class, 'ticket_id')->withoutGlobalScope(TenantScope::class);
    }
}
