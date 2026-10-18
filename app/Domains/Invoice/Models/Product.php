<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    use RecordsActors;
    use SoftDeletes;

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

    /** Computed from deleted_at for the unique indexes (0960); never written. */
    protected $hidden = ['not_deleted'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * As a customer's (Customer::attributionColumns): archiving is not
     * removal.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return [
            'created_by' => 'created_by',
            'updated_by' => 'updated_by',
            'deleted_by' => 'deleted_by',
            'created_via' => 'created_via',
            'license_id' => 'license_id',
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
            // Which product the line was made from (CatalogueRemoval).
            'product_id' => (string) $this->id,
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

    /**
     * What a UAE invoice line is filled with: the product's name is the
     * line's item name, and a product with a rate is standard-rated at it.
     *
     * @return array<string, string>
     */
    public function uaeLineFields(): array
    {
        $rated = (float) $this->tax_rate > 0;

        return [
            'product_id' => (string) $this->id,
            'item_name' => (string) $this->name,
            'unit_price' => (string) $this->unit_price,
            'unit_code' => (string) $this->unit_code,
            'tax_category' => $rated ? 'S' : 'Z',
            'tax_rate' => (string) (float) $this->tax_rate,
        ];
    }
}
