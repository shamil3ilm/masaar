<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ImportJob extends Model
{
    use BelongsToOrganization;

    public const STATUS_PENDING = 'pending';
    public const STATUS_VALIDATING = 'validating';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ENTITY_CUSTOMERS = 'customers';
    public const ENTITY_SUPPLIERS = 'suppliers';
    public const ENTITY_PRODUCTS = 'products';
    public const ENTITY_EMPLOYEES = 'employees';
    public const ENTITY_CHART_OF_ACCOUNTS = 'chart_of_accounts';
    public const ENTITY_CONTACTS = 'contacts';
    public const ENTITY_LEADS = 'leads';
    public const ENTITY_JOURNAL_ENTRIES = 'journal_entries';
    public const ENTITY_OPENING_BALANCES = 'opening_balances';

    protected $fillable = [
        'uuid',
        'organization_id',
        'user_id',
        'entity_type',
        'file_name',
        'file_path',
        'original_name',
        'file_size',
        'status',
        'total_rows',
        'processed_rows',
        'success_rows',
        'failed_rows',
        'skipped_rows',
        'column_mapping',
        'options',
        'errors',
        'summary',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'column_mapping' => 'array',
        'options' => 'array',
        'errors' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    /**
     * Get available entity types for import.
     */
    public static function getEntityTypes(): array
    {
        return [
            self::ENTITY_CUSTOMERS => [
                'name' => 'Customers',
                'module' => 'sales',
                'model' => \App\Models\Sales\Contact::class,
                'required_fields' => ['company_name'],
                'fields' => [
                    'company_name' => ['label' => 'Company Name', 'required' => true],
                    'contact_name' => ['label' => 'Contact Person', 'required' => false],
                    'email' => ['label' => 'Email', 'required' => false, 'type' => 'email'],
                    'phone' => ['label' => 'Phone', 'required' => false],
                    'tax_number' => ['label' => 'Tax Number (TRN/GSTIN)', 'required' => false],
                    'billing_address_line_1' => ['label' => 'Billing Address', 'required' => false],
                    'billing_city' => ['label' => 'Billing City', 'required' => false],
                    'billing_state' => ['label' => 'Billing State', 'required' => false],
                    'billing_postal_code' => ['label' => 'Billing Postal Code', 'required' => false],
                    'billing_country' => ['label' => 'Billing Country', 'required' => false],
                    'currency_code' => ['label' => 'Currency', 'required' => false, 'default' => 'SAR'],
                    'payment_terms' => ['label' => 'Payment Terms (days)', 'required' => false, 'type' => 'integer'],
                    'credit_limit' => ['label' => 'Credit Limit', 'required' => false, 'type' => 'decimal'],
                ],
            ],
            self::ENTITY_SUPPLIERS => [
                'name' => 'Suppliers',
                'module' => 'purchase',
                'model' => \App\Models\Sales\Contact::class,
                'required_fields' => ['company_name'],
                'fields' => [
                    'company_name' => ['label' => 'Company Name', 'required' => true],
                    'contact_name' => ['label' => 'Contact Person', 'required' => false],
                    'email' => ['label' => 'Email', 'required' => false, 'type' => 'email'],
                    'phone' => ['label' => 'Phone', 'required' => false],
                    'tax_number' => ['label' => 'Tax Number (TRN/GSTIN)', 'required' => false],
                    'billing_address_line_1' => ['label' => 'Address', 'required' => false],
                    'billing_city' => ['label' => 'City', 'required' => false],
                    'billing_state' => ['label' => 'State', 'required' => false],
                    'billing_country' => ['label' => 'Country', 'required' => false],
                    'currency_code' => ['label' => 'Currency', 'required' => false, 'default' => 'SAR'],
                    'payment_terms' => ['label' => 'Payment Terms (days)', 'required' => false, 'type' => 'integer'],
                ],
            ],
            self::ENTITY_PRODUCTS => [
                'name' => 'Products',
                'module' => 'inventory',
                'model' => \App\Models\Inventory\Product::class,
                'required_fields' => ['name', 'sku'],
                'fields' => [
                    'sku' => ['label' => 'SKU', 'required' => true],
                    'name' => ['label' => 'Product Name', 'required' => true],
                    'description' => ['label' => 'Description', 'required' => false],
                    'type' => ['label' => 'Type (goods/service)', 'required' => false, 'default' => 'goods'],
                    'category' => ['label' => 'Category Name', 'required' => false],
                    'unit' => ['label' => 'Unit of Measure', 'required' => false],
                    'purchase_price' => ['label' => 'Purchase Price', 'required' => false, 'type' => 'decimal'],
                    'selling_price' => ['label' => 'Selling Price', 'required' => false, 'type' => 'decimal'],
                    'tax_category' => ['label' => 'Tax Category', 'required' => false],
                    'hsn_code' => ['label' => 'HSN/SAC Code', 'required' => false],
                    'barcode' => ['label' => 'Barcode', 'required' => false],
                    'opening_stock' => ['label' => 'Opening Stock', 'required' => false, 'type' => 'integer'],
                    'reorder_level' => ['label' => 'Reorder Level', 'required' => false, 'type' => 'integer'],
                ],
            ],
            self::ENTITY_EMPLOYEES => [
                'name' => 'Employees',
                'module' => 'hr',
                'model' => \App\Models\HR\Employee::class,
                'required_fields' => ['first_name', 'last_name', 'email'],
                'fields' => [
                    'employee_number' => ['label' => 'Employee Number', 'required' => false],
                    'first_name' => ['label' => 'First Name', 'required' => true],
                    'last_name' => ['label' => 'Last Name', 'required' => true],
                    'email' => ['label' => 'Email', 'required' => true, 'type' => 'email'],
                    'phone' => ['label' => 'Phone', 'required' => false],
                    'date_of_birth' => ['label' => 'Date of Birth', 'required' => false, 'type' => 'date'],
                    'gender' => ['label' => 'Gender', 'required' => false],
                    'nationality' => ['label' => 'Nationality', 'required' => false],
                    'national_id' => ['label' => 'National ID', 'required' => false],
                    'department' => ['label' => 'Department Name', 'required' => false],
                    'designation' => ['label' => 'Designation/Job Title', 'required' => false],
                    'hire_date' => ['label' => 'Hire Date', 'required' => false, 'type' => 'date'],
                    'employment_type' => ['label' => 'Employment Type', 'required' => false],
                    'basic_salary' => ['label' => 'Basic Salary', 'required' => false, 'type' => 'decimal'],
                    'bank_name' => ['label' => 'Bank Name', 'required' => false],
                    'bank_account_number' => ['label' => 'Bank Account Number', 'required' => false],
                    'iban' => ['label' => 'IBAN', 'required' => false],
                ],
            ],
            self::ENTITY_CHART_OF_ACCOUNTS => [
                'name' => 'Chart of Accounts',
                'module' => 'accounting',
                'model' => \App\Models\Accounting\ChartOfAccount::class,
                'required_fields' => ['code', 'name', 'type'],
                'fields' => [
                    'code' => ['label' => 'Account Code', 'required' => true],
                    'name' => ['label' => 'Account Name', 'required' => true],
                    'type' => ['label' => 'Type (asset/liability/equity/income/expense)', 'required' => true],
                    'sub_type' => ['label' => 'Sub Type', 'required' => false],
                    'parent_code' => ['label' => 'Parent Account Code', 'required' => false],
                    'description' => ['label' => 'Description', 'required' => false],
                    'is_active' => ['label' => 'Is Active', 'required' => false, 'type' => 'boolean', 'default' => true],
                ],
            ],
            self::ENTITY_LEADS => [
                'name' => 'Leads',
                'module' => 'crm',
                'model' => \App\Models\CRM\Lead::class,
                'required_fields' => ['company_name'],
                'fields' => [
                    'company_name' => ['label' => 'Company Name', 'required' => true],
                    'contact_name' => ['label' => 'Contact Name', 'required' => false],
                    'email' => ['label' => 'Email', 'required' => false, 'type' => 'email'],
                    'phone' => ['label' => 'Phone', 'required' => false],
                    'source' => ['label' => 'Lead Source', 'required' => false],
                    'status' => ['label' => 'Status', 'required' => false],
                    'industry' => ['label' => 'Industry', 'required' => false],
                    'estimated_value' => ['label' => 'Estimated Value', 'required' => false, 'type' => 'decimal'],
                    'notes' => ['label' => 'Notes', 'required' => false],
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
     * Get progress percentage.
     */
    public function getProgressAttribute(): int
    {
        if ($this->total_rows === 0) {
            return 0;
        }

        return (int) round(($this->processed_rows / $this->total_rows) * 100);
    }

    /**
     * Check if import is in progress.
     */
    public function isProcessing(): bool
    {
        return in_array($this->status, [self::STATUS_VALIDATING, self::STATUS_PROCESSING]);
    }

    /**
     * Check if import is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if import failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
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
    public function markAsCompleted(array $summary = []): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'summary' => $summary,
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(string $error): void
    {
        $errors = $this->errors ?? [];
        $errors[] = ['row' => 0, 'error' => $error, 'is_fatal' => true];

        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
            'errors' => $errors,
        ]);
    }

    /**
     * Add row error.
     */
    public function addError(int $row, string $error, array $data = []): void
    {
        $errors = $this->errors ?? [];
        $errors[] = array_merge(['row' => $row, 'error' => $error], $data);
        $this->errors = $errors;
    }

    /**
     * Increment counters.
     */
    public function incrementProcessed(bool $success = true, bool $skipped = false): void
    {
        $this->increment('processed_rows');

        if ($skipped) {
            $this->increment('skipped_rows');
        } elseif ($success) {
            $this->increment('success_rows');
        } else {
            $this->increment('failed_rows');
        }
    }
}
