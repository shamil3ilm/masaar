<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Webhook extends Model
{
    use BelongsToOrganization;

    // Event types organized by module
    public const EVENTS = [
        // Sales events
        'invoice.created' => 'Invoice Created',
        'invoice.updated' => 'Invoice Updated',
        'invoice.posted' => 'Invoice Posted',
        'invoice.paid' => 'Invoice Fully Paid',
        'invoice.overdue' => 'Invoice Overdue',
        'invoice.voided' => 'Invoice Voided',
        'quotation.created' => 'Quotation Created',
        'quotation.accepted' => 'Quotation Accepted',
        'quotation.rejected' => 'Quotation Rejected',
        'payment.received' => 'Payment Received',

        // Purchase events
        'bill.created' => 'Bill Created',
        'bill.paid' => 'Bill Paid',
        'purchase_order.created' => 'Purchase Order Created',
        'purchase_order.approved' => 'Purchase Order Approved',
        'payment.made' => 'Payment Made',

        // Inventory events
        'product.created' => 'Product Created',
        'product.updated' => 'Product Updated',
        'stock.low' => 'Low Stock Alert',
        'stock.adjusted' => 'Stock Adjusted',
        'stock.transferred' => 'Stock Transferred',

        // Customer/Contact events
        'customer.created' => 'Customer Created',
        'customer.updated' => 'Customer Updated',
        'supplier.created' => 'Supplier Created',

        // HR events
        'employee.created' => 'Employee Created',
        'employee.updated' => 'Employee Updated',
        'employee.terminated' => 'Employee Terminated',
        'leave.requested' => 'Leave Requested',
        'leave.approved' => 'Leave Approved',
        'leave.rejected' => 'Leave Rejected',
        'payroll.processed' => 'Payroll Processed',

        // CRM events
        'lead.created' => 'Lead Created',
        'lead.converted' => 'Lead Converted',
        'opportunity.created' => 'Opportunity Created',
        'opportunity.won' => 'Opportunity Won',
        'opportunity.lost' => 'Opportunity Lost',

        // Accounting events
        'journal.posted' => 'Journal Entry Posted',
        'fiscal_year.closed' => 'Fiscal Year Closed',

        // Compliance events
        'compliance.submitted' => 'Compliance Submitted',
        'compliance.cleared' => 'Compliance Cleared',
        'compliance.rejected' => 'Compliance Rejected',
    ];

    protected $fillable = [
        'uuid',
        'organization_id',
        'created_by',
        'name',
        'url',
        'secret',
        'events',
        'headers',
        'is_active',
        'retry_count',
        'timeout_seconds',
        'content_type',
        'last_triggered_at',
        'last_success_at',
        'last_failure_at',
        'success_count',
        'failure_count',
    ];

    protected $casts = [
        'events' => 'array',
        'headers' => 'array',
        'is_active' => 'boolean',
        'last_triggered_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    protected $hidden = [
        'secret',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
            if (empty($model->secret)) {
                $model->secret = Str::random(64);
            }
        });
    }

    /**
     * Get available events grouped by module.
     */
    public static function getEventsByModule(): array
    {
        $grouped = [
            'sales' => [],
            'purchase' => [],
            'inventory' => [],
            'contacts' => [],
            'hr' => [],
            'crm' => [],
            'accounting' => [],
            'compliance' => [],
        ];

        foreach (self::EVENTS as $event => $label) {
            $prefix = explode('.', $event)[0];

            $module = match ($prefix) {
                'invoice', 'quotation', 'payment' => 'sales',
                'bill', 'purchase_order' => 'purchase',
                'product', 'stock' => 'inventory',
                'customer', 'supplier' => 'contacts',
                'employee', 'leave', 'payroll' => 'hr',
                'lead', 'opportunity' => 'crm',
                'journal', 'fiscal_year' => 'accounting',
                'compliance' => 'compliance',
                default => 'other',
            };

            if (isset($grouped[$module])) {
                $grouped[$module][$event] = $label;
            }
        }

        return array_filter($grouped, fn ($events) => !empty($events));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * Check if webhook subscribes to an event.
     */
    public function subscribesTo(string $eventType): bool
    {
        return in_array($eventType, $this->events ?? []) || in_array('*', $this->events ?? []);
    }

    /**
     * Generate signature for payload.
     */
    public function generateSignature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }

    /**
     * Record successful delivery.
     */
    public function recordSuccess(): void
    {
        $this->increment('success_count');
        $this->update([
            'last_triggered_at' => now(),
            'last_success_at' => now(),
        ]);
    }

    /**
     * Record failed delivery.
     */
    public function recordFailure(): void
    {
        $this->increment('failure_count');
        $this->update([
            'last_triggered_at' => now(),
            'last_failure_at' => now(),
        ]);
    }

    /**
     * Get masked secret for display.
     */
    public function getMaskedSecretAttribute(): string
    {
        if (!$this->secret) {
            return '';
        }

        return substr($this->secret, 0, 8) . str_repeat('*', 24) . substr($this->secret, -8);
    }

    /**
     * Get success rate.
     */
    public function getSuccessRateAttribute(): float
    {
        $total = $this->success_count + $this->failure_count;
        if ($total === 0) {
            return 0;
        }

        return round(($this->success_count / $total) * 100, 2);
    }

    /**
     * Regenerate secret.
     */
    public function regenerateSecret(): string
    {
        $this->secret = Str::random(64);
        $this->save();

        return $this->secret;
    }
}
