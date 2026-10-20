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
 * A sale an insurer pays for, wholly or in part (InsuranceSplits): its
 * invoice - to the patient, with the amounts for the account of the insurer
 * and of the patient, or to the insurer that contracted for the care - and,
 * once the insurer has decided, the credit note and the patient's invoice
 * its decision called for. Each names it (insurance_split_id), so any one
 * finds the others.
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $branch_id
 * @property string $recipient
 * @property string|null $insurer_customer_id
 * @property string $insurer_name
 * @property string|null $insurer_vat_number
 * @property string|null $patient_name
 * @property string|null $patient_name_ar
 * @property string|null $patient_id
 * @property string|null $patient_id_scheme
 * @property string $method
 * @property string $patient_share
 * @property string $insurer_share
 * @property list<array<string, mixed>>|null $line_treatments
 * @property string|null $decision
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

    /** The patient is the recipient, and their invoice the sale's (Healthcare Guideline §5.1, p21). */
    public const PATIENT = 'patient';

    /** The insurer is the recipient, having contracted for the care (§5.1 Example 11, p23). */
    public const INSURER = 'insurer';

    public const RECIPIENTS = [self::PATIENT, self::INSURER];

    /** The patient's invoice for what the insurer did not cover (§5.3.2, p25). */
    public const REBILL = 'rebill';

    /** How the patient's share of an invoice to the patient is set. */
    public const METHODS = ['percent', 'amount', 'lines'];

    /** The insurer is the recipient: the patient has no share (§5.1 Example 11). */
    public const NO_SHARE = 'none';

    /** Why an insurer approved less: the care was not covered, or its agreed price applies (§5.3.1, §5.3.2). */
    public const NOT_COVERED = 'not_covered';

    public const PRICE = 'price';

    public const BILLED = 'billed';

    public const SETTLED = 'settled';

    protected $fillable = [
        'org_id',
        'branch_id',
        'recipient',
        'insurer_customer_id',
        'insurer_name',
        'insurer_vat_number',
        'patient_name',
        'patient_name_ar',
        'patient_id',
        'patient_id_scheme',
        'method',
        'patient_percent',
        'patient_amount',
        'patient_share',
        'insurer_share',
        'line_treatments',
        'currency',
        'sale_subtotal',
        'sale_tax',
        'sale_total',
        'policy_number',
        'member_id',
        'claim_reference',
        'status',
        'insurer_approved',
        'decision',
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
            'patient_share' => 'decimal:2',
            'insurer_share' => 'decimal:2',
            'line_treatments' => 'array',
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
     * Its invoices: the sale's, and the patient's for what the insurer did
     * not cover.
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
            ...$this->only(['id', 'recipient', 'insurer_name', 'insurer_vat_number', 'method', 'patient_percent', 'patient_amount',
                'patient_share', 'insurer_share', 'currency', 'sale_subtotal', 'sale_tax', 'sale_total',
                'policy_number', 'member_id', 'claim_reference', 'status', 'insurer_approved', 'decision', 'credit_note_id', 'rebill_invoice_id']),
            'billed' => $this->billed(),
        ];
    }

    /** The invoice the sale was billed on: the patient's, or the insurer's. */
    public function sale(): ?Invoice
    {
        return $this->share($this->recipient);
    }

    /**
     * What the insurer owes: its share of an invoice to the patient - what
     * it approved, once it has decided - or its invoice less the notes
     * against it when it is the recipient.
     */
    public function insurerOwes(): string
    {
        if ($this->recipient === self::PATIENT) {
            return bcadd((string) ($this->insurer_approved ?? $this->insurer_share), '0', 2);
        }

        $owed = (string) ($this->sale()?->total ?? '0');
        $number = $this->sale()?->invoice_number;

        foreach ($this->notes()->where('billing_ref', $number) as $note) {
            $owed = $note->document_type === DocumentType::CreditNote ? bcsub($owed, (string) $note->total, 2) : bcadd($owed, (string) $note->total, 2);
        }

        return $owed;
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
