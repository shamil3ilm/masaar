<?php

declare(strict_types=1);

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    protected $fillable = [
        'payment_received_id',
        'invoice_id',
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
        return $this->belongsTo(PaymentReceived::class, 'payment_received_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
