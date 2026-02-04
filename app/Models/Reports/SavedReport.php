<?php

declare(strict_types=1);

namespace App\Models\Reports;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavedReport extends Model
{
    use HasFactory, HasUuid, BelongsToOrganization;

    public const TYPE_BALANCE_SHEET = 'balance_sheet';
    public const TYPE_INCOME_STATEMENT = 'income_statement';
    public const TYPE_CASH_FLOW = 'cash_flow';
    public const TYPE_TRIAL_BALANCE = 'trial_balance';
    public const TYPE_GENERAL_LEDGER = 'general_ledger';
    public const TYPE_ACCOUNTS_RECEIVABLE = 'accounts_receivable';
    public const TYPE_ACCOUNTS_PAYABLE = 'accounts_payable';
    public const TYPE_AGED_RECEIVABLES = 'aged_receivables';
    public const TYPE_AGED_PAYABLES = 'aged_payables';
    public const TYPE_STOCK_VALUATION = 'stock_valuation';
    public const TYPE_STOCK_MOVEMENT = 'stock_movement';
    public const TYPE_SALES_BY_CUSTOMER = 'sales_by_customer';
    public const TYPE_SALES_BY_PRODUCT = 'sales_by_product';
    public const TYPE_SALES_BY_SALESPERSON = 'sales_by_salesperson';
    public const TYPE_PURCHASE_BY_SUPPLIER = 'purchase_by_supplier';
    public const TYPE_TAX_REPORT = 'tax_report';
    public const TYPE_VAT_RETURN = 'vat_return';
    public const TYPE_GST_RETURN = 'gst_return';

    public const SCHEDULE_DAILY = 'daily';
    public const SCHEDULE_WEEKLY = 'weekly';
    public const SCHEDULE_MONTHLY = 'monthly';
    public const SCHEDULE_QUARTERLY = 'quarterly';

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'report_type',
        'parameters',
        'columns',
        'schedule_frequency',
        'schedule_day',
        'schedule_time',
        'recipients',
        'export_format',
        'is_public',
        'last_run_at',
        'next_run_at',
        'is_active',
    ];

    protected $casts = [
        'parameters' => 'array',
        'columns' => 'array',
        'recipients' => 'array',
        'is_public' => 'boolean',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'schedule_time' => 'datetime:H:i',
    ];

    /**
     * Get all report types.
     */
    public static function getReportTypes(): array
    {
        return [
            // Financial Reports
            'financial' => [
                self::TYPE_BALANCE_SHEET => [
                    'name' => 'Balance Sheet',
                    'description' => 'Statement of financial position',
                    'category' => 'financial',
                ],
                self::TYPE_INCOME_STATEMENT => [
                    'name' => 'Income Statement (P&L)',
                    'description' => 'Profit and loss statement',
                    'category' => 'financial',
                ],
                self::TYPE_CASH_FLOW => [
                    'name' => 'Cash Flow Statement',
                    'description' => 'Statement of cash flows',
                    'category' => 'financial',
                ],
                self::TYPE_TRIAL_BALANCE => [
                    'name' => 'Trial Balance',
                    'description' => 'List of all account balances',
                    'category' => 'financial',
                ],
                self::TYPE_GENERAL_LEDGER => [
                    'name' => 'General Ledger',
                    'description' => 'Detailed account transactions',
                    'category' => 'financial',
                ],
            ],

            // Receivables & Payables
            'receivables_payables' => [
                self::TYPE_ACCOUNTS_RECEIVABLE => [
                    'name' => 'Accounts Receivable',
                    'description' => 'Customer balances and transactions',
                    'category' => 'receivables',
                ],
                self::TYPE_ACCOUNTS_PAYABLE => [
                    'name' => 'Accounts Payable',
                    'description' => 'Supplier balances and transactions',
                    'category' => 'payables',
                ],
                self::TYPE_AGED_RECEIVABLES => [
                    'name' => 'Aged Receivables',
                    'description' => 'Customer aging analysis',
                    'category' => 'receivables',
                ],
                self::TYPE_AGED_PAYABLES => [
                    'name' => 'Aged Payables',
                    'description' => 'Supplier aging analysis',
                    'category' => 'payables',
                ],
            ],

            // Inventory Reports
            'inventory' => [
                self::TYPE_STOCK_VALUATION => [
                    'name' => 'Stock Valuation',
                    'description' => 'Inventory value by product/warehouse',
                    'category' => 'inventory',
                ],
                self::TYPE_STOCK_MOVEMENT => [
                    'name' => 'Stock Movement',
                    'description' => 'Inventory movement history',
                    'category' => 'inventory',
                ],
            ],

            // Sales Reports
            'sales' => [
                self::TYPE_SALES_BY_CUSTOMER => [
                    'name' => 'Sales by Customer',
                    'description' => 'Sales analysis by customer',
                    'category' => 'sales',
                ],
                self::TYPE_SALES_BY_PRODUCT => [
                    'name' => 'Sales by Product',
                    'description' => 'Sales analysis by product',
                    'category' => 'sales',
                ],
                self::TYPE_SALES_BY_SALESPERSON => [
                    'name' => 'Sales by Salesperson',
                    'description' => 'Sales performance by salesperson',
                    'category' => 'sales',
                ],
            ],

            // Purchase Reports
            'purchase' => [
                self::TYPE_PURCHASE_BY_SUPPLIER => [
                    'name' => 'Purchase by Supplier',
                    'description' => 'Purchase analysis by supplier',
                    'category' => 'purchase',
                ],
            ],

            // Tax Reports
            'tax' => [
                self::TYPE_TAX_REPORT => [
                    'name' => 'Tax Report',
                    'description' => 'Tax summary by category',
                    'category' => 'tax',
                ],
                self::TYPE_VAT_RETURN => [
                    'name' => 'VAT Return',
                    'description' => 'GCC VAT return preparation',
                    'category' => 'tax',
                ],
                self::TYPE_GST_RETURN => [
                    'name' => 'GST Return',
                    'description' => 'India GST return preparation',
                    'category' => 'tax',
                ],
            ],
        ];
    }

    /**
     * Get schedule options.
     */
    public static function getScheduleOptions(): array
    {
        return [
            self::SCHEDULE_DAILY => 'Daily',
            self::SCHEDULE_WEEKLY => 'Weekly',
            self::SCHEDULE_MONTHLY => 'Monthly',
            self::SCHEDULE_QUARTERLY => 'Quarterly',
        ];
    }

    /**
     * Get export format options.
     */
    public static function getExportFormats(): array
    {
        return [
            'pdf' => 'PDF Document',
            'xlsx' => 'Excel Spreadsheet',
            'csv' => 'CSV File',
            'json' => 'JSON Data',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(ReportExecution::class);
    }

    public function latestExecution(): HasMany
    {
        return $this->hasMany(ReportExecution::class)->latest()->limit(1);
    }

    /**
     * Calculate next run date based on schedule.
     */
    public function calculateNextRunAt(): ?\DateTime
    {
        if (!$this->schedule_frequency) {
            return null;
        }

        $now = now();
        $time = $this->schedule_time ?? '08:00';

        return match ($this->schedule_frequency) {
            self::SCHEDULE_DAILY => $now->copy()->addDay()->setTimeFromTimeString($time),
            self::SCHEDULE_WEEKLY => $now->copy()->next($this->schedule_day ?? 'monday')->setTimeFromTimeString($time),
            self::SCHEDULE_MONTHLY => $now->copy()->addMonth()->startOfMonth()->addDays(($this->schedule_day ?? 1) - 1)->setTimeFromTimeString($time),
            self::SCHEDULE_QUARTERLY => $now->copy()->addQuarter()->startOfQuarter()->setTimeFromTimeString($time),
            default => null,
        };
    }

    /**
     * Scope for scheduled reports due to run.
     */
    public function scopeDueForRun($query)
    {
        return $query->where('is_active', true)
            ->whereNotNull('schedule_frequency')
            ->where(function ($q) {
                $q->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            });
    }
}
