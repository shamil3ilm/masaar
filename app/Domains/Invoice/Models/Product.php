<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Something an organization sells, kept so an invoice line starts filled in.
 *
 * A line copies these when it is written; a new price changes no invoice
 * already issued.
 */
class Product extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'org_id',
        'name',
        'name_ar',
        'sku',
        'unit_code',
        'unit_price',
        'tax_rate',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * What an invoice line is filled with.
     *
     * @return array<string, string>
     */
    public function lineFields(): array
    {
        return [
            'description' => (string) $this->name,
            'description_ar' => (string) $this->name_ar,
            'unit_price' => (string) $this->unit_price,
            // 15, not 15.00; and 0, not an empty string.
            'tax_rate' => (string) (float) $this->tax_rate,
            'unit_code' => (string) $this->unit_code,
            // Cleared: a product is standard-rated at its own rate, and a
            // line prefilled from a zero-rated original must not keep that
            // original's category under the product's price.
            'tax_category' => '',
            'exempt_code' => '',
            'exempt_reason' => '',
            'class_code' => '',
        ];
    }
}
