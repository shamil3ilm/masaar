<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Someone the platform buys from: hosting, its e-invoicing service
 * provider, its card gateway, a subscription. Written by Suppliers only,
 * which checks the IBAN, the country and the currency.
 *
 * The platform's own, so not tenant-scoped. Removed only while no expense
 * names it; archived otherwise (archived_at), which keeps it on those
 * expenses and out of the pickers.
 *
 * @property string $id
 * @property string $name
 * @property string $country
 * @property string|null $vat_number
 * @property string|null $address
 * @property string $currency
 * @property int $payment_terms_days
 * @property string|null $bank_name
 * @property string|null $iban
 * @property string|null $swift
 * @property string|null $category
 * @property string|null $notes
 * @property Carbon|null $archived_at
 */
class Supplier extends Model
{
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'country',
        'vat_number',
        'address',
        'currency',
        'payment_terms_days',
        'bank_name',
        'iban',
        'swift',
        'category',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function archived(): bool
    {
        return $this->archived_at !== null;
    }
}
