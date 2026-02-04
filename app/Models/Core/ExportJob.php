<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ExportJob extends Model
{
    use BelongsToOrganization;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const FORMAT_XLSX = 'xlsx';
    public const FORMAT_CSV = 'csv';
    public const FORMAT_PDF = 'pdf';

    public const ENTITY_CUSTOMERS = 'customers';
    public const ENTITY_SUPPLIERS = 'suppliers';
    public const ENTITY_PRODUCTS = 'products';
    public const ENTITY_INVOICES = 'invoices';
    public const ENTITY_BILLS = 'bills';
    public const ENTITY_EMPLOYEES = 'employees';
    public const ENTITY_CHART_OF_ACCOUNTS = 'chart_of_accounts';
    public const ENTITY_JOURNAL_ENTRIES = 'journal_entries';
    public const ENTITY_STOCK_LEVELS = 'stock_levels';
    public const ENTITY_LEADS = 'leads';
    public const ENTITY_OPPORTUNITIES = 'opportunities';

    protected $fillable = [
        'uuid',
        'organization_id',
        'user_id',
        'entity_type',
        'format',
        'status',
        'filters',
        'columns',
        'options',
        'total_records',
        'file_name',
        'file_path',
        'file_size',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'columns' => 'array',
        'options' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
            if (empty($model->expires_at)) {
                // Exports expire after 7 days
                $model->expires_at = now()->addDays(7);
            }
        });
    }

    /**
     * Get available entity types for export.
     */
    public static function getEntityTypes(): array
    {
        return [
            self::ENTITY_CUSTOMERS => [
                'name' => 'Customers',
                'module' => 'sales',
                'columns' => [
                    'company_name' => 'Company Name',
                    'contact_name' => 'Contact Person',
                    'email' => 'Email',
                    'phone' => 'Phone',
                    'tax_number' => 'Tax Number',
                    'billing_address' => 'Billing Address',
                    'currency_code' => 'Currency',
                    'payment_terms' => 'Payment Terms',
                    'credit_limit' => 'Credit Limit',
                    'outstanding_balance' => 'Outstanding Balance',
                    'created_at' => 'Created Date',
                ],
            ],
            self::ENTITY_SUPPLIERS => [
                'name' => 'Suppliers',
                'module' => 'purchase',
                'columns' => [
                    'company_name' => 'Company Name',
                    'contact_name' => 'Contact Person',
                    'email' => 'Email',
                    'phone' => 'Phone',
                    'tax_number' => 'Tax Number',
                    'billing_address' => 'Address',
                    'currency_code' => 'Currency',
                    'payment_terms' => 'Payment Terms',
                    'payable_balance' => 'Payable Balance',
                    'created_at' => 'Created Date',
                ],
            ],
            self::ENTITY_PRODUCTS => [
                'name' => 'Products',
                'module' => 'inventory',
                'columns' => [
                    'sku' => 'SKU',
                    'name' => 'Product Name',
                    'description' => 'Description',
                    'type' => 'Type',
                    'category' => 'Category',
                    'unit' => 'Unit',
                    'purchase_price' => 'Purchase Price',
                    'selling_price' => 'Selling Price',
                    'tax_category' => 'Tax Category',
                    'hsn_code' => 'HSN/SAC Code',
                    'stock_on_hand' => 'Stock on Hand',
                    'stock_value' => 'Stock Value',
                    'is_active' => 'Active',
                ],
            ],
            self::ENTITY_INVOICES => [
                'name' => 'Invoices',
                'module' => 'sales',
                'columns' => [
                    'invoice_number' => 'Invoice Number',
                    'invoice_date' => 'Invoice Date',
                    'due_date' => 'Due Date',
                    'customer_name' => 'Customer',
                    'subtotal' => 'Subtotal',
                    'tax_amount' => 'Tax Amount',
                    'total' => 'Total',
                    'amount_paid' => 'Amount Paid',
                    'amount_due' => 'Amount Due',
                    'status' => 'Status',
                    'compliance_status' => 'Compliance Status',
                ],
            ],
            self::ENTITY_EMPLOYEES => [
                'name' => 'Employees',
                'module' => 'hr',
                'columns' => [
                    'employee_number' => 'Employee Number',
                    'first_name' => 'First Name',
                    'last_name' => 'Last Name',
                    'email' => 'Email',
                    'phone' => 'Phone',
                    'department' => 'Department',
                    'designation' => 'Designation',
                    'hire_date' => 'Hire Date',
                    'employment_type' => 'Employment Type',
                    'status' => 'Status',
                ],
            ],
            self::ENTITY_CHART_OF_ACCOUNTS => [
                'name' => 'Chart of Accounts',
                'module' => 'accounting',
                'columns' => [
                    'code' => 'Account Code',
                    'name' => 'Account Name',
                    'type' => 'Type',
                    'sub_type' => 'Sub Type',
                    'parent' => 'Parent Account',
                    'balance' => 'Current Balance',
                    'is_active' => 'Active',
                ],
            ],
            self::ENTITY_STOCK_LEVELS => [
                'name' => 'Stock Levels',
                'module' => 'inventory',
                'columns' => [
                    'product_sku' => 'SKU',
                    'product_name' => 'Product Name',
                    'warehouse' => 'Warehouse',
                    'quantity' => 'Quantity',
                    'reserved_quantity' => 'Reserved',
                    'available_quantity' => 'Available',
                    'average_cost' => 'Average Cost',
                    'stock_value' => 'Stock Value',
                    'reorder_level' => 'Reorder Level',
                ],
            ],
            self::ENTITY_LEADS => [
                'name' => 'Leads',
                'module' => 'crm',
                'columns' => [
                    'company_name' => 'Company Name',
                    'contact_name' => 'Contact Name',
                    'email' => 'Email',
                    'phone' => 'Phone',
                    'source' => 'Lead Source',
                    'status' => 'Status',
                    'estimated_value' => 'Estimated Value',
                    'assigned_to' => 'Assigned To',
                    'created_at' => 'Created Date',
                ],
            ],
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Check if export is ready for download.
     */
    public function isReady(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->file_path && !$this->isExpired();
    }

    /**
     * Check if export has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Mark as processing.
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    /**
     * Mark as completed.
     */
    public function markAsCompleted(string $filePath, string $fileName, int $fileSize, int $totalRecords): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'total_records' => $totalRecords,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Get download URL.
     */
    public function getDownloadUrl(): ?string
    {
        if (!$this->isReady()) {
            return null;
        }

        return route('api.v1.exports.download', $this->uuid);
    }
}
