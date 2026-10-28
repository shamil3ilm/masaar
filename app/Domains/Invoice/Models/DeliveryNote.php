<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A delivery to a customer, not yet a tax document: a month's summary tax
 * invoice gathers it (SummaryInvoices). Written by DeliveryNotes; once a
 * summary takes it (invoice_id) it is neither changed nor removed.
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $branch_id
 * @property string $customer_id
 * @property string $number
 * @property Carbon $delivered_on
 * @property string|null $reference
 * @property list<array<string, mixed>> $lines
 * @property string $net_amount
 * @property string|null $invoice_id
 */
class DeliveryNote extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    protected $fillable = [
        'org_id', 'branch_id', 'customer_id', 'number', 'delivered_on', 'reference', 'lines', 'net_amount', 'invoice_id',
    ];

    /** @var list<string> */
    protected $hidden = ['deleted_at', 'deleted_by'];

    protected function casts(): array
    {
        return [
            'delivered_on' => 'date',
            'lines' => 'array',
            'net_amount' => 'decimal:2',
        ];
    }

    /**
     * Who made it, changed and removed it, and how it arrived.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return [
            'created_by' => 'created_by', 'updated_by' => 'updated_by', 'deleted_by' => 'deleted_by',
            'created_via' => 'created_via', 'license_id' => 'license_id',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Whether a summary invoice has taken it. */
    public function invoiced(): bool
    {
        return $this->invoice_id !== null;
    }
}
