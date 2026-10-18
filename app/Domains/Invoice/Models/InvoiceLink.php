<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link that opens one issued invoice without signing in (InvoiceLinks).
 *
 * Found by token_hash; token is the link's secret, encrypted at rest so the
 * QR can be drawn again, and never searched on.
 *
 * @property string $id
 * @property string $org_id
 * @property string $invoice_id
 * @property string $token_hash
 * @property string $token
 * @property string|null $created_by
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_opened_at
 * @property int $open_count
 */
class InvoiceLink extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    protected $fillable = ['org_id', 'invoice_id', 'token_hash', 'token', 'created_by'];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'revoked_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'open_count' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
