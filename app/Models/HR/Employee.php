<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Branch;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use BelongsToOrganization, HasUuid, SoftDeletes;

    public const EMPLOYMENT_TYPE_FULL_TIME = 'full_time';
    public const EMPLOYMENT_TYPE_PART_TIME = 'part_time';
    public const EMPLOYMENT_TYPE_CONTRACT = 'contract';
    public const EMPLOYMENT_TYPE_INTERN = 'intern';
    public const EMPLOYMENT_TYPE_PROBATION = 'probation';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ON_NOTICE = 'on_notice';
    public const STATUS_TERMINATED = 'terminated';
    public const STATUS_RESIGNED = 'resigned';
    public const STATUS_ABSCONDED = 'absconded';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'user_id',
        'employee_number',
        'first_name',
        'middle_name',
        'last_name',
        'display_name',
        'date_of_birth',
        'gender',
        'marital_status',
        'nationality',
        'blood_group',
        'email',
        'personal_email',
        'phone',
        'mobile',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'department_id',
        'designation_id',
        'reporting_manager_id',
        'joining_date',
        'confirmation_date',
        'termination_date',
        'termination_reason',
        'employment_type',
        'employment_status',
        'work_schedule',
        'shift_start',
        'shift_end',
        'work_days',
        'national_id',
        'passport_number',
        'passport_expiry',
        'visa_number',
        'visa_expiry',
        'work_permit_number',
        'work_permit_expiry',
        'tax_number',
        'social_security_number',
        'tax_declarations',
        'currency_code',
        'payment_mode',
        'bank_name',
        'bank_account_number',
        'bank_ifsc_code',
        'bank_iban',
        'notes',
        'profile_photo_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'joining_date' => 'date',
            'confirmation_date' => 'date',
            'termination_date' => 'date',
            'passport_expiry' => 'date',
            'visa_expiry' => 'date',
            'work_permit_expiry' => 'date',
            'shift_start' => 'datetime:H:i',
            'shift_end' => 'datetime:H:i',
            'work_days' => 'array',
            'tax_declarations' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function reportingManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'reporting_manager_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(EmployeeQualification::class);
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(EmployeeExperience::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function currentSalary(): HasOne
    {
        return $this->hasOne(EmployeeSalary::class)->where('is_current', true);
    }

    public function salaryHistory(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class)->orderBy('effective_from', 'desc');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(EmployeeLoan::class);
    }

    public function getFullName(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }

    public function getDisplayName(): string
    {
        return $this->display_name ?? $this->getFullName();
    }

    public function getAge(): ?int
    {
        return $this->date_of_birth?->age;
    }

    public function getTenureInYears(): ?float
    {
        if (!$this->joining_date) {
            return null;
        }

        $endDate = $this->termination_date ?? now();
        return round($this->joining_date->diffInDays($endDate) / 365, 2);
    }

    public function getTenureInMonths(): ?int
    {
        if (!$this->joining_date) {
            return null;
        }

        $endDate = $this->termination_date ?? now();
        return $this->joining_date->diffInMonths($endDate);
    }

    public function isOnProbation(): bool
    {
        if ($this->employment_type === self::EMPLOYMENT_TYPE_PROBATION) {
            return true;
        }

        return !$this->confirmation_date && $this->joining_date;
    }

    public function isActive(): bool
    {
        return $this->is_active && $this->employment_status === self::STATUS_ACTIVE;
    }

    public function hasExpiringDocument(int $daysThreshold = 30): bool
    {
        $checkDate = now()->addDays($daysThreshold);

        if ($this->passport_expiry && $this->passport_expiry->lte($checkDate)) {
            return true;
        }
        if ($this->visa_expiry && $this->visa_expiry->lte($checkDate)) {
            return true;
        }
        if ($this->work_permit_expiry && $this->work_permit_expiry->lte($checkDate)) {
            return true;
        }

        return $this->documents()
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $checkDate)
            ->exists();
    }

    public function getLeaveBalance(int $leaveTypeId, int $year = null): float
    {
        $year = $year ?? now()->year;

        $balance = $this->leaveBalances()
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->first();

        return $balance ? (float) $balance->closing_balance : 0;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('employment_status', self::STATUS_ACTIVE);
    }

    public function scopeInDepartment($query, int $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    public function scopeWithDesignation($query, int $designationId)
    {
        return $query->where('designation_id', $designationId);
    }

    public function scopeJoinedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('joining_date', [$startDate, $endDate]);
    }

    public function scopeOnProbation($query)
    {
        return $query->whereNull('confirmation_date')
            ->where('is_active', true);
    }

    public function scopeExpiringDocuments($query, int $days = 30)
    {
        $checkDate = now()->addDays($days);

        return $query->where(function ($q) use ($checkDate) {
            $q->where('passport_expiry', '<=', $checkDate)
                ->orWhere('visa_expiry', '<=', $checkDate)
                ->orWhere('work_permit_expiry', '<=', $checkDate);
        });
    }
}
