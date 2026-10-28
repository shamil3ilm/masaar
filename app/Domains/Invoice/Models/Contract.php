<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A contract billed in stages, with retention withheld from each stage
 * and released later (ProgressBilling).
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $branch_id
 * @property string $customer_id
 * @property string $reference
 * @property string $title
 * @property string|null $title_ar
 * @property string $value
 * @property string $retention_percent
 * @property string $tax_category
 * @property string $tax_rate
 * @property string|null $exempt_code
 * @property string|null $exempt_reason
 */
class Contract extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'org_id', 'branch_id', 'customer_id', 'reference', 'title', 'title_ar', 'value', 'retention_percent',
        'tax_category', 'tax_rate', 'exempt_code', 'exempt_reason',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'retention_percent' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }

    /**
     * Who made and changed it, and how it arrived.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'updated_by', 'created_via' => 'created_via', 'license_id' => 'license_id'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return HasMany<ContractStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(ContractStage::class)->orderBy('position');
    }

    /** @return HasMany<ContractInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(ContractInvoice::class)->orderBy('created_at');
    }
}
