<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Notification extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'type',
        'title',
        'message',
        'icon',
        'color',
        'action_url',
        'action_text',
        'notifiable_type',
        'notifiable_id',
        'data',
        'channel',
        'read_at',
        'sent_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    // Notification type constants
    public const TYPE_INVOICE_CREATED = 'invoice.created';
    public const TYPE_INVOICE_SENT = 'invoice.sent';
    public const TYPE_INVOICE_PAID = 'invoice.paid';
    public const TYPE_INVOICE_OVERDUE = 'invoice.overdue';
    public const TYPE_PAYMENT_RECEIVED = 'payment.received';
    public const TYPE_PAYMENT_MADE = 'payment.made';
    public const TYPE_STOCK_LOW = 'stock.low';
    public const TYPE_STOCK_OUT = 'stock.out';
    public const TYPE_LEAVE_REQUESTED = 'leave.requested';
    public const TYPE_LEAVE_APPROVED = 'leave.approved';
    public const TYPE_LEAVE_REJECTED = 'leave.rejected';
    public const TYPE_PAYROLL_GENERATED = 'payroll.generated';
    public const TYPE_PAYSLIP_AVAILABLE = 'payslip.available';
    public const TYPE_DOCUMENT_EXPIRING = 'document.expiring';
    public const TYPE_LEAD_ASSIGNED = 'lead.assigned';
    public const TYPE_OPPORTUNITY_WON = 'opportunity.won';
    public const TYPE_WORK_ORDER_CREATED = 'work_order.created';
    public const TYPE_WORK_ORDER_COMPLETED = 'work_order.completed';
    public const TYPE_REPORT_READY = 'report.ready';
    public const TYPE_SYSTEM_ALERT = 'system.alert';
    public const TYPE_USER_MENTIONED = 'user.mentioned';

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead(): void
    {
        if (!$this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }

    /**
     * Mark notification as unread.
     */
    public function markAsUnread(): void
    {
        $this->update(['read_at' => null]);
    }

    /**
     * Check if notification is read.
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Scope unread notifications.
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope read notifications.
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Get icon based on type.
     */
    public function getDefaultIcon(): string
    {
        return match (explode('.', $this->type)[0] ?? '') {
            'invoice' => 'file-text',
            'payment' => 'credit-card',
            'stock' => 'package',
            'leave' => 'calendar',
            'payroll', 'payslip' => 'dollar-sign',
            'document' => 'file',
            'lead' => 'user-plus',
            'opportunity' => 'target',
            'work_order' => 'tool',
            'report' => 'bar-chart',
            'system' => 'alert-circle',
            'user' => 'at-sign',
            default => 'bell',
        };
    }

    /**
     * Get color based on type.
     */
    public function getDefaultColor(): string
    {
        return match ($this->type) {
            self::TYPE_INVOICE_PAID, self::TYPE_PAYMENT_RECEIVED, self::TYPE_LEAVE_APPROVED,
            self::TYPE_OPPORTUNITY_WON, self::TYPE_WORK_ORDER_COMPLETED => '#22c55e',
            self::TYPE_INVOICE_OVERDUE, self::TYPE_STOCK_OUT, self::TYPE_LEAVE_REJECTED => '#ef4444',
            self::TYPE_STOCK_LOW, self::TYPE_DOCUMENT_EXPIRING => '#f59e0b',
            default => '#3b82f6',
        };
    }

    /**
     * Get all notification types with labels.
     */
    public static function getTypes(): array
    {
        return [
            // Sales
            self::TYPE_INVOICE_CREATED => 'Invoice Created',
            self::TYPE_INVOICE_SENT => 'Invoice Sent',
            self::TYPE_INVOICE_PAID => 'Invoice Paid',
            self::TYPE_INVOICE_OVERDUE => 'Invoice Overdue',
            self::TYPE_PAYMENT_RECEIVED => 'Payment Received',
            self::TYPE_PAYMENT_MADE => 'Payment Made',
            // Inventory
            self::TYPE_STOCK_LOW => 'Low Stock Alert',
            self::TYPE_STOCK_OUT => 'Out of Stock',
            // HR
            self::TYPE_LEAVE_REQUESTED => 'Leave Request Submitted',
            self::TYPE_LEAVE_APPROVED => 'Leave Request Approved',
            self::TYPE_LEAVE_REJECTED => 'Leave Request Rejected',
            self::TYPE_PAYROLL_GENERATED => 'Payroll Generated',
            self::TYPE_PAYSLIP_AVAILABLE => 'Payslip Available',
            self::TYPE_DOCUMENT_EXPIRING => 'Document Expiring',
            // CRM
            self::TYPE_LEAD_ASSIGNED => 'Lead Assigned',
            self::TYPE_OPPORTUNITY_WON => 'Opportunity Won',
            // Manufacturing
            self::TYPE_WORK_ORDER_CREATED => 'Work Order Created',
            self::TYPE_WORK_ORDER_COMPLETED => 'Work Order Completed',
            // System
            self::TYPE_REPORT_READY => 'Report Ready',
            self::TYPE_SYSTEM_ALERT => 'System Alert',
            self::TYPE_USER_MENTIONED => 'You were mentioned',
        ];
    }

    /**
     * Get types grouped by module.
     */
    public static function getTypesGrouped(): array
    {
        return [
            'sales' => [
                self::TYPE_INVOICE_CREATED,
                self::TYPE_INVOICE_SENT,
                self::TYPE_INVOICE_PAID,
                self::TYPE_INVOICE_OVERDUE,
                self::TYPE_PAYMENT_RECEIVED,
                self::TYPE_PAYMENT_MADE,
            ],
            'inventory' => [
                self::TYPE_STOCK_LOW,
                self::TYPE_STOCK_OUT,
            ],
            'hr' => [
                self::TYPE_LEAVE_REQUESTED,
                self::TYPE_LEAVE_APPROVED,
                self::TYPE_LEAVE_REJECTED,
                self::TYPE_PAYROLL_GENERATED,
                self::TYPE_PAYSLIP_AVAILABLE,
                self::TYPE_DOCUMENT_EXPIRING,
            ],
            'crm' => [
                self::TYPE_LEAD_ASSIGNED,
                self::TYPE_OPPORTUNITY_WON,
            ],
            'manufacturing' => [
                self::TYPE_WORK_ORDER_CREATED,
                self::TYPE_WORK_ORDER_COMPLETED,
            ],
            'system' => [
                self::TYPE_REPORT_READY,
                self::TYPE_SYSTEM_ALERT,
                self::TYPE_USER_MENTIONED,
            ],
        ];
    }
}
