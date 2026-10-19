<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One sale billed in two shares, each an invoice of its own: the patient's
 * and the insurer's (InsuranceSplits). Both name it (insurance_split_id), so
 * either finds the other; it keeps how the sale was divided, what the sale
 * came to, the insurer's references and, once recorded, what the insurer
 * approved.
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $branch_id
 * @property string|null $insurer_customer_id
 * @property string $method
 * @property string|null $patient_percent
 * @property string|null $patient_amount
 * @property string $currency
 * @property string $sale_subtotal
 * @property string $sale_tax
 * @property string $sale_total
 * @property string|null $policy_number
 * @property string|null $member_id
 * @property string|null $claim_reference
 * @property string $status
 * @property string|null $insurer_approved
 * @property string|null $credit_note_id
 * @property string|null $rebill_invoice_id
 * @property Carbon|null $settled_at
 */
class InsuranceSplit extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const PATIENT = 'patient';

    public const INSURER = 'insurer';

    /** The patient's invoice for what the insurer declined. */
    public const REBILL = 'rebill';

    public const METHODS = ['percent', 'amount', 'lines'];

    public const BILLED = 'billed';

    public const SETTLED = 'settled';

    protected $fillable = [
        'org_id',
        'branch_id',
        'insurer_customer_id',
        'method',
        'patient_percent',
        'patient_amount',
        'currency',
        'sale_subtotal',
        'sale_tax',
        'sale_total',
        'policy_number',
        'member_id',
        'claim_reference',
        'status',
        'insurer_approved',
        'credit_note_id',
        'rebill_invoice_id',
        'idempotency_key',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'patient_percent' => 'decimal:2',
            'patient_amount' => 'decimal:2',
            'sale_subtotal' => 'decimal:2',
            'sale_tax' => 'decimal:2',
            'sale_total' => 'decimal:2',
            'insurer_approved' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * A financial record: who split the sale, how it arrived and through
     * which licence. Who recorded the insurer's decision is settled_by.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'created_via' => 'created_via', 'license_id' => 'license_id'];
    }

    /**
     * Its invoices: the two shares, and the patient's for what the insurer
     * declined.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderBy('created_at');
    }

    /** @return BelongsTo<Customer, $this> */
    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'insurer_customer_id')->withTrashed();
    }

    /** @return BelongsTo<Invoice, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'credit_note_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function rebill(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'rebill_invoice_id');
    }

    public function share(string $role): ?Invoice
    {
        return $this->invoices->firstWhere('split_role', $role);
    }

    /**
     * The credit and debit notes issued against its invoices, found by the
     * invoice each names (BT-25): one written by hand on the note form is
     * as much part of the sale as the one the insurer's decision made.
     *
     * @return Collection<int, Invoice>
     */
    public function notes(): Collection
    {
        $numbers = $this->invoices->pluck('invoice_number')->filter()->values();

        if ($numbers->isEmpty()) {
            return collect();
        }

        return Invoice::withoutTenantScope(fn () => Invoice::query()
            ->where('org_id', $this->org_id)
            ->whereIn('document_type', [DocumentType::CreditNote->value, DocumentType::DebitNote->value])
            ->whereIn('billing_ref', $numbers->all())
            ->orderBy('created_at')
            ->get());
    }

    /**
     * How it was divided, what the sale came to, the insurer's references
     * and decision, and what its documents now bill - for the API.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            ...$this->only(['id', 'method', 'patient_percent', 'patient_amount', 'currency', 'sale_subtotal', 'sale_tax', 'sale_total',
                'policy_number', 'member_id', 'claim_reference', 'status', 'insurer_approved', 'credit_note_id', 'rebill_invoice_id']),
            'billed' => $this->billed(),
        ];
    }

    /**
     * What its documents bill, net of the notes against them: the sale's
     * total while nothing has been credited that was not billed again.
     */
    public function billed(): string
    {
        $sum = '0.00';

        foreach ($this->invoices as $invoice) {
            $sum = bcadd($sum, (string) $invoice->total, 2);
        }

        foreach ($this->notes() as $note) {
            $sum = $note->document_type === DocumentType::CreditNote
                ? bcsub($sum, (string) $note->total, 2)
                : bcadd($sum, (string) $note->total, 2);
        }

        return $sum;
    }
}
