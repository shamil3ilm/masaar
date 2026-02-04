<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class NumberSequence extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'type',
        'prefix',
        'suffix',
        'current_number',
        'padding',
        'include_year',
        'include_month',
        'reset_yearly',
        'reset_monthly',
        'last_reset_year',
        'last_reset_month',
    ];

    protected $casts = [
        'current_number' => 'integer',
        'padding' => 'integer',
        'include_year' => 'boolean',
        'include_month' => 'boolean',
        'reset_yearly' => 'boolean',
        'reset_monthly' => 'boolean',
        'last_reset_year' => 'integer',
        'last_reset_month' => 'integer',
    ];

    // Default sequence configurations by type
    public const DEFAULT_CONFIGS = [
        'invoice' => ['prefix' => 'INV-', 'padding' => 6],
        'credit_note' => ['prefix' => 'CN-', 'padding' => 6],
        'debit_note' => ['prefix' => 'DN-', 'padding' => 6],
        'quotation' => ['prefix' => 'QUO-', 'padding' => 5],
        'sales_order' => ['prefix' => 'SO-', 'padding' => 5],
        'purchase_order' => ['prefix' => 'PO-', 'padding' => 5],
        'bill' => ['prefix' => 'BILL-', 'padding' => 5],
        'payment_received' => ['prefix' => 'REC-', 'padding' => 5],
        'payment_made' => ['prefix' => 'PAY-', 'padding' => 5],
        'journal' => ['prefix' => 'JE-', 'padding' => 5],
        'work_order' => ['prefix' => 'WO-', 'padding' => 5],
        'employee' => ['prefix' => 'EMP-', 'padding' => 4],
        'payslip' => ['prefix' => 'PS-', 'padding' => 6, 'include_month' => true],
        'leave_request' => ['prefix' => 'LR-', 'padding' => 5],
        'expense' => ['prefix' => 'EXP-', 'padding' => 5],
        'stock_transfer' => ['prefix' => 'ST-', 'padding' => 5],
        'stock_adjustment' => ['prefix' => 'ADJ-', 'padding' => 5],
        'grn' => ['prefix' => 'GRN-', 'padding' => 5],
    ];

    // Relationships

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // Scopes

    public function scopeForType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeForBranch($query, ?int $branchId)
    {
        if ($branchId) {
            return $query->where('branch_id', $branchId);
        }
        return $query->whereNull('branch_id');
    }

    // Helpers

    public function needsYearlyReset(): bool
    {
        return $this->reset_yearly && $this->last_reset_year !== now()->year;
    }

    public function needsMonthlyReset(): bool
    {
        return $this->reset_monthly && $this->last_reset_month !== now()->month;
    }

    public function getFormattedNumber(): string
    {
        $parts = [];

        if ($this->prefix) {
            $parts[] = $this->prefix;
        }

        if ($this->include_year) {
            $parts[] = now()->format('Y');
        }

        if ($this->include_month) {
            $parts[] = now()->format('m');
        }

        $parts[] = str_pad((string) $this->current_number, $this->padding, '0', STR_PAD_LEFT);

        if ($this->suffix) {
            $parts[] = $this->suffix;
        }

        return implode('', $parts);
    }

    // Static methods for number generation

    public static function getNext(int $organizationId, string $type, ?int $branchId = null): string
    {
        return DB::transaction(function () use ($organizationId, $type, $branchId) {
            $sequence = static::where('organization_id', $organizationId)
                ->where('type', $type)
                ->where(function ($q) use ($branchId) {
                    if ($branchId) {
                        $q->where('branch_id', $branchId)->orWhereNull('branch_id');
                    } else {
                        $q->whereNull('branch_id');
                    }
                })
                ->orderByRaw('branch_id IS NULL')  // Prefer branch-specific
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                $sequence = static::createDefault($organizationId, $type, $branchId);
            }

            // Check if reset is needed
            $sequence->checkAndReset();

            // Increment
            $sequence->current_number++;
            $sequence->save();

            return $sequence->getFormattedNumber();
        });
    }

    public static function peekNext(int $organizationId, string $type, ?int $branchId = null): string
    {
        $sequence = static::where('organization_id', $organizationId)
            ->where('type', $type)
            ->where(function ($q) use ($branchId) {
                if ($branchId) {
                    $q->where('branch_id', $branchId)->orWhereNull('branch_id');
                } else {
                    $q->whereNull('branch_id');
                }
            })
            ->orderByRaw('branch_id IS NULL')
            ->first();

        if (!$sequence) {
            $sequence = static::createDefault($organizationId, $type, $branchId);
        }

        // Simulate next number
        $tempSequence = clone $sequence;
        $tempSequence->current_number++;

        return $tempSequence->getFormattedNumber();
    }

    public static function createDefault(int $organizationId, string $type, ?int $branchId): static
    {
        $config = static::DEFAULT_CONFIGS[$type] ?? ['prefix' => strtoupper($type) . '-', 'padding' => 5];

        return static::create([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'type' => $type,
            'prefix' => $config['prefix'] ?? null,
            'suffix' => $config['suffix'] ?? null,
            'current_number' => 0,
            'padding' => $config['padding'] ?? 5,
            'include_year' => $config['include_year'] ?? true,
            'include_month' => $config['include_month'] ?? false,
            'reset_yearly' => $config['reset_yearly'] ?? true,
            'reset_monthly' => $config['reset_monthly'] ?? false,
            'last_reset_year' => now()->year,
            'last_reset_month' => now()->month,
        ]);
    }

    public function checkAndReset(): void
    {
        $now = now();
        $needsReset = false;

        if ($this->reset_yearly && $this->last_reset_year !== $now->year) {
            $needsReset = true;
            $this->last_reset_year = $now->year;
        }

        if ($this->reset_monthly && $this->last_reset_month !== $now->month) {
            $needsReset = true;
            $this->last_reset_month = $now->month;
        }

        if ($needsReset) {
            $this->current_number = 0;
        }
    }
}
