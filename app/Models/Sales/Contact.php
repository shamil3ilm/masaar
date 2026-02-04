<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use BelongsToOrganization, HasUuid, SoftDeletes;

    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_SUPPLIER = 'supplier';
    public const TYPE_BOTH = 'both';

    protected $fillable = [
        'organization_id',
        'contact_type',
        'company_name',
        'contact_name',
        'email',
        'phone',
        'mobile',
        'website',
        'tax_number',
        'tax_registration_name',
        'payment_terms',
        'credit_limit',
        'currency_code',
        'receivable_account_id',
        'payable_account_id',
        'billing_address_line_1',
        'billing_address_line_2',
        'billing_city',
        'billing_state',
        'billing_postal_code',
        'billing_country_code',
        'shipping_address_line_1',
        'shipping_address_line_2',
        'shipping_city',
        'shipping_state',
        'shipping_postal_code',
        'shipping_country_code',
        'notes',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_terms' => 'integer',
            'credit_limit' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'receivable_account_id');
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'payable_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'customer_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'customer_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'customer_id');
    }

    public function paymentsReceived(): HasMany
    {
        return $this->hasMany(PaymentReceived::class, 'customer_id');
    }

    /**
     * Check if contact is a customer.
     */
    public function isCustomer(): bool
    {
        return in_array($this->contact_type, [self::TYPE_CUSTOMER, self::TYPE_BOTH], true);
    }

    /**
     * Check if contact is a supplier.
     */
    public function isSupplier(): bool
    {
        return in_array($this->contact_type, [self::TYPE_SUPPLIER, self::TYPE_BOTH], true);
    }

    /**
     * Get display name.
     */
    public function getDisplayName(): string
    {
        return $this->company_name ?? $this->contact_name;
    }

    /**
     * Get full billing address.
     */
    public function getBillingAddress(): string
    {
        $parts = array_filter([
            $this->billing_address_line_1,
            $this->billing_address_line_2,
            $this->billing_city,
            $this->billing_state,
            $this->billing_postal_code,
            $this->billing_country_code,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get full shipping address.
     */
    public function getShippingAddress(): string
    {
        $parts = array_filter([
            $this->shipping_address_line_1,
            $this->shipping_address_line_2,
            $this->shipping_city,
            $this->shipping_state,
            $this->shipping_postal_code,
            $this->shipping_country_code,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get outstanding invoice balance.
     */
    public function getOutstandingBalance(): float
    {
        return $this->invoices()
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->sum('amount_due');
    }

    /**
     * Check if customer is over credit limit.
     */
    public function isOverCreditLimit(): bool
    {
        if (!$this->credit_limit || $this->credit_limit <= 0) {
            return false;
        }

        return $this->getOutstandingBalance() >= $this->credit_limit;
    }

    /**
     * Get available credit.
     */
    public function getAvailableCredit(): float
    {
        if (!$this->credit_limit || $this->credit_limit <= 0) {
            return PHP_FLOAT_MAX;
        }

        return max(0, $this->credit_limit - $this->getOutstandingBalance());
    }

    public function scopeCustomers($query)
    {
        return $query->whereIn('contact_type', [self::TYPE_CUSTOMER, self::TYPE_BOTH]);
    }

    public function scopeSuppliers($query)
    {
        return $query->whereIn('contact_type', [self::TYPE_SUPPLIER, self::TYPE_BOTH]);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('company_name', 'like', "%{$term}%")
                ->orWhere('contact_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('tax_number', 'like', "%{$term}%");
        });
    }
}
