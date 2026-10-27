<?php

declare(strict_types=1);

namespace App\Domains\Books\Models;

use App\Domains\Books\Enums\PurchaseTreatment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a purchase invoice, written with it and never changed. Who
 * wrote it is on the invoice.
 *
 * @property string $id
 * @property string $purchase_invoice_id
 * @property int $position
 * @property string $description
 * @property PurchaseTreatment $treatment
 * @property string $vat_rate
 * @property string $net
 * @property string $vat
 * @property string $net_tax
 * @property string $vat_tax
 * @property bool $is_capital_asset
 */
class PurchaseLine extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'purchase_invoice_id',
        'position',
        'description',
        'treatment',
        'vat_rate',
        'net',
        'vat',
        'net_tax',
        'vat_tax',
        'is_capital_asset',
        // What goods a domestic reverse-charge line is (the country's list).
        'goods_type',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'treatment' => PurchaseTreatment::class,
            'vat_rate' => 'decimal:2',
            'net' => 'decimal:2',
            'vat' => 'decimal:2',
            'net_tax' => 'decimal:2',
            'vat_tax' => 'decimal:2',
            'is_capital_asset' => 'boolean',
        ];
    }
}
