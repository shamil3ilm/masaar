<?php

declare(strict_types=1);

namespace App\Models\Purchase;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillPaymentAllocation extends Model
{
    protected $fillable = [
        'payment_made_id',
        'bill_id',
        'amount',
        'base_amount',
        'allocated_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'base_amount' => 'decimal:4',
            'allocated_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentMade::class, 'payment_made_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
