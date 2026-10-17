<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the list of common charges an invoice form offers, at one
 * level: the platform's (no organization), an organization's, or one
 * person's in it. Read through ChargePresets, which merges the three.
 *
 * Not tenant-scoped: the platform's rows belong to no organization, and
 * ChargePresets names the organization on every query.
 */
class ChargePreset extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id',
        'user_id',
        'key',
        'position',
        'reason',
        'reason_code',
        'percent',
        'amount',
        'tax_category',
        'tax_rate',
        'disabled',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'percent' => 'decimal:2',
            'amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'disabled' => 'boolean',
        ];
    }
}
