<?php

declare(strict_types=1);

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'subject',
        'body_html',
        'body_text',
        'from_name',
        'reply_to',
        'cc',
        'bcc',
        'variables',
        'language',
        'is_active',
        'is_system',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    // Template codes
    public const INVOICE_CREATED = 'invoice_created';
    public const INVOICE_REMINDER = 'invoice_reminder';
    public const INVOICE_OVERDUE = 'invoice_overdue';
    public const INVOICE_PAID = 'invoice_paid';
    public const QUOTATION_SENT = 'quotation_sent';
    public const QUOTATION_ACCEPTED = 'quotation_accepted';
    public const QUOTATION_REJECTED = 'quotation_rejected';
    public const QUOTATION_EXPIRED = 'quotation_expired';
    public const PO_CREATED = 'po_created';
    public const PO_APPROVED = 'po_approved';
    public const PAYMENT_RECEIVED = 'payment_received';
    public const PAYMENT_MADE = 'payment_made';
    public const WELCOME_USER = 'welcome_user';
    public const PASSWORD_RESET = 'password_reset';
    public const LEAVE_REQUEST_SUBMITTED = 'leave_request_submitted';
    public const LEAVE_REQUEST_APPROVED = 'leave_request_approved';
    public const LEAVE_REQUEST_REJECTED = 'leave_request_rejected';
    public const PAYSLIP_GENERATED = 'payslip_generated';
    public const LOW_STOCK_ALERT = 'low_stock_alert';
    public const APPROVAL_REQUIRED = 'approval_required';
    public const APPROVAL_COMPLETED = 'approval_completed';

    // Default templates with their variables
    public const TEMPLATE_DEFINITIONS = [
        self::INVOICE_CREATED => [
            'name' => 'Invoice Created',
            'subject' => 'Invoice {{invoice_number}} from {{company_name}}',
            'variables' => [
                'company_name', 'company_email', 'company_phone',
                'customer_name', 'invoice_number', 'invoice_date', 'due_date',
                'subtotal', 'tax_amount', 'total', 'currency',
                'invoice_url', 'payment_url',
            ],
        ],
        self::INVOICE_REMINDER => [
            'name' => 'Invoice Payment Reminder',
            'subject' => 'Reminder: Invoice {{invoice_number}} - Payment Due {{due_date}}',
            'variables' => [
                'company_name', 'customer_name',
                'invoice_number', 'invoice_date', 'due_date', 'days_until_due',
                'amount_due', 'currency',
                'invoice_url', 'payment_url',
            ],
        ],
        self::INVOICE_OVERDUE => [
            'name' => 'Invoice Overdue',
            'subject' => 'Overdue: Invoice {{invoice_number}} - {{days_overdue}} days past due',
            'variables' => [
                'company_name', 'customer_name',
                'invoice_number', 'invoice_date', 'due_date', 'days_overdue',
                'amount_due', 'currency',
                'invoice_url', 'payment_url',
            ],
        ],
        self::INVOICE_PAID => [
            'name' => 'Invoice Paid Confirmation',
            'subject' => 'Payment Received - Invoice {{invoice_number}}',
            'variables' => [
                'company_name', 'customer_name',
                'invoice_number', 'payment_date', 'payment_amount', 'payment_method',
                'receipt_url',
            ],
        ],
        self::QUOTATION_SENT => [
            'name' => 'Quotation Sent',
            'subject' => 'Quotation {{quotation_number}} from {{company_name}}',
            'variables' => [
                'company_name', 'customer_name',
                'quotation_number', 'quotation_date', 'valid_until',
                'subtotal', 'tax_amount', 'total', 'currency',
                'quotation_url', 'accept_url',
            ],
        ],
        self::PO_CREATED => [
            'name' => 'Purchase Order Created',
            'subject' => 'Purchase Order {{po_number}} from {{company_name}}',
            'variables' => [
                'company_name', 'supplier_name',
                'po_number', 'po_date', 'expected_date',
                'subtotal', 'tax_amount', 'total', 'currency',
                'po_url',
            ],
        ],
        self::WELCOME_USER => [
            'name' => 'Welcome New User',
            'subject' => 'Welcome to {{company_name}}',
            'variables' => [
                'company_name', 'user_name', 'user_email',
                'login_url', 'temp_password',
            ],
        ],
        self::PASSWORD_RESET => [
            'name' => 'Password Reset',
            'subject' => 'Reset Your Password - {{company_name}}',
            'variables' => [
                'company_name', 'user_name',
                'reset_url', 'expires_in',
            ],
        ],
        self::PAYSLIP_GENERATED => [
            'name' => 'Payslip Generated',
            'subject' => 'Your Payslip for {{pay_period}} - {{company_name}}',
            'variables' => [
                'company_name', 'employee_name',
                'pay_period', 'payment_date',
                'gross_earnings', 'deductions', 'net_salary', 'currency',
                'payslip_url',
            ],
        ],
        self::LOW_STOCK_ALERT => [
            'name' => 'Low Stock Alert',
            'subject' => 'Low Stock Alert: {{product_count}} items need attention',
            'variables' => [
                'company_name', 'product_count',
                'products_list', // HTML list of low stock products
                'inventory_url',
            ],
        ],
        self::APPROVAL_REQUIRED => [
            'name' => 'Approval Required',
            'subject' => 'Approval Required: {{document_type}} {{document_number}}',
            'variables' => [
                'company_name', 'approver_name', 'requester_name',
                'document_type', 'document_number', 'amount', 'currency',
                'document_url', 'approve_url', 'reject_url',
            ],
        ],
    ];

    // Relationships

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }

    public function scopeForOrganization($query, ?int $organizationId)
    {
        return $query->where(function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId)
                ->orWhereNull('organization_id');
        });
    }

    // Static methods

    public static function getTemplate(string $code, ?int $organizationId = null, string $language = 'en'): ?static
    {
        // Try organization-specific template first
        if ($organizationId) {
            $template = static::where('code', $code)
                ->where('organization_id', $organizationId)
                ->where('language', $language)
                ->where('is_active', true)
                ->first();

            if ($template) {
                return $template;
            }
        }

        // Fall back to system template
        return static::where('code', $code)
            ->whereNull('organization_id')
            ->where('language', $language)
            ->where('is_active', true)
            ->first();
    }

    public static function getAvailableTemplates(): array
    {
        return self::TEMPLATE_DEFINITIONS;
    }

    // Instance methods

    public function render(array $data): array
    {
        $subject = $this->replaceVariables($this->subject, $data);
        $bodyHtml = $this->replaceVariables($this->body_html, $data);
        $bodyText = $this->body_text ? $this->replaceVariables($this->body_text, $data) : strip_tags($bodyHtml);

        return [
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'from_name' => $this->from_name ? $this->replaceVariables($this->from_name, $data) : null,
            'reply_to' => $this->reply_to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
        ];
    }

    protected function replaceVariables(string $content, array $data): string
    {
        foreach ($data as $key => $value) {
            if (is_scalar($value) || is_null($value)) {
                $content = str_replace('{{' . $key . '}}', (string) ($value ?? ''), $content);
            }
        }

        // Remove any unreplaced variables
        $content = preg_replace('/\{\{[^}]+\}\}/', '', $content);

        return $content;
    }

    public function getAvailableVariables(): array
    {
        return $this->variables ?? self::TEMPLATE_DEFINITIONS[$this->code]['variables'] ?? [];
    }

    public function duplicate(?int $organizationId = null): static
    {
        $new = $this->replicate();
        $new->organization_id = $organizationId;
        $new->is_system = false;
        $new->save();
        return $new;
    }
}
