<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Invoice\Contracts\FilingRecord;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Support\LineSnapshot;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invoice aggregate root.
 *
 * Core business entity for ZATCA compliance.
 * Immutable after status changes from Draft.
 */
class Invoice extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'org_id',
        // The EGS unit that issued this invoice. A branch signs with its own
        // ZATCA certificate, so this decides which credentials are used.
        'branch_id',
        // The device that signed it, whose counter and chain it is in: the
        // branch when it had its own certificate, head office (org_id)
        // otherwise. Set at issuance.
        'device_id',
        'profile_id',
        'invoice_number',
        'type',
        'document_type',
        'status',
        'issue_date',
        'supply_date',
        // KSA-24: the last day of a supply over a period, such as a
        // subscription. Needs supply_date beside it (BR-KSA-35).
        'supply_end_date',
        'issued_at',
        // Who issued it: the person signed in when it left draft.
        'issued_by',
        // When a till made the sale, as it sent it: the actual supply.
        'sold_at',
        // When payment is due: PINT AE's IBT-009, and on a Saudi invoice
        // kept and shown, since ZATCA's document has no due date.
        'due_date',
        // A country's own document fields - for the UAE the transaction
        // flags, buyer endpoint and emirate.
        'jurisdiction_data',
        'currency',
        'exchange_rate',
        'buyer_name',
        'buyer_name_ar',
        'buyer_vat_number',
        'buyer_id',
        'buyer_id_scheme',
        'buyer_address',
        'payment_means_code',
        // The terms it is to be paid on, in words (KSA-22, IBT-020).
        'payment_terms',
        'billing_ref',
        // The buyer's purchase order (BT-13), contract (BT-12) and own
        // reference (BT-10), for matching the invoice on their side.
        'order_reference',
        'contract_reference',
        'buyer_reference',
        'adjustment_reason',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        // What its prepayments came to with their VAT (BT-113); the buyer
        // pays the total less it.
        'prepaid_amount',
        'hash',
        'qr_code',
        'signed_xml',
        'cleared_xml',
        'icv',
        'zatca_response',
        'notes',
        'erp_reference_id',
        // The pipeline Idempotency-Key it was created under, so a retry finds it.
        'idempotency_key',
        // The fingerprint of the request first sent under that key.
        'idempotency_hash',
        // Whether its prices were entered with VAT in them. Frozen with the
        // rest of the document once issued.
        'prices_include_vat',
        // ZATCA BT-3 invoice sub-type flags (bits 3-7)
        'is_third_party',
        'is_nominal',
        'is_export',
        'is_summary',
        // A business-to-government transaction (KSA-2 position 9).
        'is_government',
        'is_self_billed',
        // The split sale it bills a share of (InsuranceSplit), and which
        // share: patient, insurer, or rebill - the patient's invoice for
        // what the insurer declined.
        'insurance_split_id',
        'split_role',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'sold_at' => 'datetime',
            'type' => InvoiceType::class,
            'document_type' => DocumentType::class,
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'supply_date' => 'date',
            'supply_end_date' => 'date',
            'due_date' => 'date',
            'jurisdiction_data' => 'array',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'prepaid_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'icv' => 'integer',
            'zatca_response' => 'array',
            'buyer_address' => 'array',
            'is_third_party' => 'boolean',
            'is_nominal' => 'boolean',
            'is_government' => 'boolean',
            'is_export' => 'boolean',
            'is_summary' => 'boolean',
            'is_self_billed' => 'boolean',
            'prices_include_vat' => 'boolean',
        ];
    }

    /**
     * The only fields that may change once an invoice has left draft.
     *
     * Everything else is the document: what was signed, numbered and chained.
     * This used to be the other way round - a list of fields that could not
     * change, read by the guard below, while this list was never read. Any
     * column missing from that list was writable after issue, including the
     * five flags that make up the signed type name (export, summary, nominal,
     * third party, self-billed) and every column added since. A new column is
     * now frozen until it is added here on purpose.
     *
     * Each one, and why it may still move:
     *
     * - status: the filing lifecycle (issued, accepted, rejected), along
     *   InvoiceStatus::next() only and never back to a draft. It is the
     *   authority's verdict on the document, not part of it.
     * - zatca_response: what the authority answered - validation results,
     *   warnings, errors. Written with the status, outside the document.
     * - updated_at: when the row last moved, which those two writes change.
     *
     * notes and erp_reference_id were here and are not any more: notes print
     * on the invoice (the PDF and the receipt), so a note added after issue
     * made a reprint differ from the copy the buyer was given; the ERP
     * reference is how the integration finds the document and nothing needs
     * to move it once issued. Both are a draft's to set.
     *
     * The database refuses the same changes (migration 1814, MySQL), so a
     * write below the model - a query-builder update, a console session - is
     * refused as well.
     */
    public const MUTABLE_AFTER_FINALIZED = [
        'status',
        'zatca_response',
        'updated_at',
    ];

    /**
     * The stamp, written once after the document is fixed and never again.
     *
     * Issuance writes hash, qr_code and signed_xml in the same save that
     * leaves the draft. A document issued during an outage is signed by the
     * offline fallback afterwards, which is the one later writer
     * (OfflineFallback). Clearance brings cleared_xml - ZATCA's stamped copy -
     * and with it the QR the authority put there (KSA-14 on a standard
     * invoice is applied by ZATCA), so qr_code may be replaced once, in the
     * same write that first sets cleared_xml (InvoiceVerdict). Anything else -
     * a second signature, a rewritten hash, a cleared copy replaced - changes
     * a document already given to the buyer and chained by its successor
     * (ZATCA E-Invoicing Detailed Guidelines 5.6, prohibited functions, p27).
     */
    public const SET_ONCE_AFTER_FINALIZED = [
        'hash',
        'qr_code',
        'signed_xml',
        'cleared_xml',
    ];

    /**
     * Boot method for ICV generation and immutability enforcement.
     *
     * COMPLIANCE: Invoices are immutable after leaving Draft status.
     * This is a core ZATCA requirement - issued invoices cannot be modified.
     */
    protected static function boot(): void
    {
        parent::boot();

        // ICV is not allocated here.
        //
        // It used to be, and that is what let the chain fork. The counter was
        // taken when the row was created and the hash written later, at
        // issuance, so the two moments could interleave. The PIH accessor
        // skips anything unhashed, so a document issued while a lower-numbered
        // draft was still unsigned chained straight past it — and when that
        // draft was issued in turn, both named the same predecessor. Neither
        // unique index catches that: they constrain (device_id, icv) and
        // invoice_id, and two rows at different positions may share a
        // previous_hash.
        //
        // Allocating at issuance instead makes the chain contiguous by
        // construction. A draft carries no counter, so an abandoned one costs
        // nothing and cannot block the tenant — which matters because there is
        // no cancelled status to release a number with.
        //
        // Submitter::generate() allocates, under the same device lock that
        // reads the PIH, so the pair is decided together.
        // ChainForkTest holds this.

        // A counter belongs to a device's sequence. Something that sets one
        // without naming the device - a fixture, a backfill - is head
        // office's, which is what every document was before devices had
        // chains. Left null it would sit outside every sequence, and the
        // (device_id, icv) unique index treats nulls as distinct, so nothing
        // would stop a second invoice taking the same number.
        static::saving(function (Invoice $invoice) {
            if ($invoice->icv !== null && $invoice->device_id === null) {
                $invoice->device_id = $invoice->org_id;
            }
        });

        // The saved customer it is for, decided while it is a draft and then
        // frozen with the rest of the document. The buyer fields stay what
        // the invoice says; this only says whose they are.
        static::saving(function (Invoice $invoice) {
            $status = $invoice->getOriginal('status');

            if (($status === null || $status === InvoiceStatus::Draft)
                && ($invoice->customer_id === null || $invoice->isDirty(['buyer_vat_number', 'buyer_id', 'buyer_id_scheme']))) {
                $invoice->customer_id = $invoice->savedCustomerId();
            }
        });

        // Prevent deletion of finalized invoices
        static::deleting(function (Invoice $invoice) {
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new \RuntimeException(
                    'Finalized invoices cannot be deleted. '.
                    'This is a tax authority requirement. '.
                    'Use credit/debit notes for corrections.'
                );
            }
        });

        // Leaving the draft: each line keeps what it printed from its
        // product, so the printed copy stays the issued one (LineSnapshot).
        static::updating(function (Invoice $invoice) {
            if ($invoice->isDirty('status') && $invoice->getOriginal('status') === InvoiceStatus::Draft && $invoice->status !== InvoiceStatus::Draft) {
                LineSnapshot::take($invoice);
            }
        });

        // The status moves only along InvoiceStatus::next(): a draft is
        // issued before the authority can answer for it, never back to a
        // draft, and never away from an acceptance.
        static::updating(function (Invoice $invoice) {
            $from = $invoice->getOriginal('status');

            if ($invoice->isDirty('status') && $from instanceof InvoiceStatus
                && $invoice->status instanceof InvoiceStatus && ! $from->allows($invoice->status)) {
                throw new \RuntimeException(sprintf(
                    "An invoice cannot move from '%s' to '%s'.",
                    $from->value,
                    $invoice->status->value,
                ));
            }
        });

        // Enforce immutability on finalized invoices
        static::updating(function (Invoice $invoice) {
            $originalStatus = $invoice->getOriginal('status');

            // If invoice was/is in draft, allow all changes
            if ($originalStatus === InvoiceStatus::Draft || $originalStatus === null) {
                return;
            }

            // Invoice is finalized - only the fields listed as mutable may move,
            // and the stamp only from empty to set (SET_ONCE_AFTER_FINALIZED).
            $changedFields = array_keys($invoice->getDirty());
            $clearing = $invoice->getOriginal('cleared_xml') === null && $invoice->cleared_xml !== null;
            $immutableChanges = array_values(array_filter(
                array_diff($changedFields, self::MUTABLE_AFTER_FINALIZED),
                fn (string $field): bool => ! in_array($field, self::SET_ONCE_AFTER_FINALIZED, true)
                    || ($invoice->getOriginal($field) !== null && ! ($field === 'qr_code' && $clearing)),
            ));

            if (! empty($immutableChanges)) {
                throw new \RuntimeException(
                    'Finalized invoice fields cannot be modified. '.
                    'This is a tax authority requirement. '.
                    'Attempted to change: '.implode(', ', $immutableChanges).'. '.
                    'Use credit/debit notes for corrections.'
                );
            }
        });
    }

    /**
     * A compliance record: who drafted it, how it arrived and through which
     * licence. Who issued it is issued_by, set at issuance.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'created_via' => 'created_via', 'license_id' => 'license_id'];
    }

    /**
     * The organization's customer this invoice's buyer is: the one with its
     * VAT number, or for a buyer with none, the one with its identifier and
     * register - the match Catalogue::sameBuyer makes when it saves a buyer.
     * A removed customer is no one's; an archived one still is.
     */
    private function savedCustomerId(): ?string
    {
        $vat = $this->buyer_vat_number ?: null;

        if ($vat === null && (blank($this->buyer_id) || blank($this->buyer_id_scheme))) {
            return null;
        }

        $id = Customer::acrossTenants()
            ->where('org_id', $this->org_id)
            ->when($vat !== null,
                fn ($query) => $query->where('vat_number', $vat),
                fn ($query) => $query->whereNull('vat_number')->where('buyer_id', $this->buyer_id)->where('buyer_id_scheme', $this->buyer_id_scheme))
            ->orderBy('created_at')
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * The split sale this invoice bills a share of, when it does.
     *
     * @return BelongsTo<InsuranceSplit, $this>
     */
    public function insuranceSplit(): BelongsTo
    {
        return $this->belongsTo(InsuranceSplit::class);
    }

    /**
     * Allocate the next ICV for a device.
     *
     * ZATCA requires the invoice counter to be strictly sequential per EGS
     * unit, and the PIH chain is built on it, so two invoices from one device
     * must never receive the same value. A device is head office (its id is
     * the organization's) or a branch with its own certificate (the branch's).
     *
     * The caller holds the device's issuing lock (IssuingLifecycle, scoped by
     * the jurisdiction to the device), which is what makes reading the
     * highest counter and taking the next one safe: every issuance into this
     * device's chain waits on that lock, and issuances into other devices'
     * chains read other counters. Locking `SELECT MAX(icv) ... FOR UPDATE`
     * instead has a hole - a device's first invoice has no rows to lock, so
     * two requests both read none and both allocate 1.
     *
     * `invoices_device_icv_unique` is the backstop: a collision fails the
     * write rather than corrupting the chain.
     */
    public static function generateNextIcv(string $organizationId, ?string $deviceId = null): int
    {
        $deviceId ??= $organizationId;
        // Outside the tenant scope: the organization is given explicitly,
        // and the counter must reflect every invoice that organization
        // holds regardless of who is asking. Left scoped, a request with
        // no tenant context reads zero rows, restarts the counter at 1,
        // and the unique index rejects the insert.
        $highest = static::acrossTenants()->where('org_id', $organizationId)->where('device_id', $deviceId)->max('icv');

        return ((int) $highest) + 1;
    }

    /**
     * Organization that owns this invoice.
     */
    public function org(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The branch this invoice was issued for: its address is the seller's.
     * The till that signed it is unit().
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The till (EGS unit) whose counter and chain it is in, named by
     * device_id: head office's under the organization's own id. Set when it
     * is issued, or on the draft when the request names a till.
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(EgsUnit::class, 'device_id');
    }

    /**
     * Compliance profile used for this invoice's jurisdiction.
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ComplianceProfile::class);
    }

    /**
     * Invoice line items.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * The charges on the whole invoice (BG-21), in the order entered. A
     * line's own are its charges().
     */
    public function charges(): HasMany
    {
        return $this->hasMany(InvoiceCharge::class)->whereNull('invoice_line_id')->orderBy('position');
    }

    /**
     * The prepayments this final invoice settles, a row per prepaid tax
     * category.
     */
    public function prepayments(): HasMany
    {
        return $this->hasMany(InvoicePrepayment::class)->orderBy('position');
    }

    /**
     * What the buyer pays: the total less any prepayments (BT-115).
     */
    public function payable(): string
    {
        return bcsub((string) $this->total, (string) ($this->prepaid_amount ?? '0'), 2);
    }

    /**
     * Check if invoice can be edited.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Check if invoice requires ZATCA clearance.
     */
    public function requiresClearance(): bool
    {
        return $this->type->requiresClearance();
    }

    /**
     * The document of record: what to archive, and what to hand anyone who
     * asks for this invoice.
     *
     * For a standard invoice, ZATCA clears it and returns its own stamped
     * copy. That copy is the legal invoice; the one we submitted is a
     * proposal the authority has since signed off. For a simplified invoice
     * there is no clearance — it is reported after the fact — so what we
     * signed is what stands.
     *
     * Both are kept. signed_xml is what we sent, which is the evidence of what
     * we asked for; cleared_xml is what came back, which is the invoice.
     */
    public function getLegalXmlAttribute(): ?string
    {
        return $this->cleared_xml ?? $this->signed_xml;
    }

    /**
     * When the invoice was issued: KSA-25, the QR's timestamp and the start
     * of the reporting clock.
     *
     * created_at for an invoice issued before issued_at was recorded - that
     * is the time its signed document already carries, so reading anything
     * else would contradict it.
     */
    public function issuedMoment(): CarbonInterface
    {
        return $this->issued_at ?? $this->created_at;
    }

    /**
     * Check if invoice is B2B (Standard - requires clearance).
     */
    public function isB2B(): bool
    {
        return $this->type === InvoiceType::Standard;
    }

    /**
     * The authority's latest filing of this invoice, where its country files
     * invoice by invoice; null where it does not, or nothing was filed yet.
     *
     * The relation behind it, latestSubmission, is the country module's to
     * register (Model::resolveRelationUsing), as is submissions: this model
     * names no country's records.
     */
    public function latestFiling(): ?FilingRecord
    {
        $filing = $this->isRelation('latestSubmission') ? $this->getRelationValue('latestSubmission') : null;

        return $filing instanceof FilingRecord ? $filing : null;
    }

    /**
     * The hash of this tenant's preceding invoice — ZATCA's PIH.
     *
     * Every document carries the previous one's hash, and the authority checks
     * the chain. There is no previous_invoice_hash column, so this accessor is
     * what makes the attribute answer: Eloquent returns null for an attribute
     * it does not have rather than failing, and XmlBuilder turns a null into
     * the genesis PIH — a silent claim to be first in the chain.
     *
     * Defined on the model rather than at each call site, so that the queue,
     * the offline path and the direct path all derive the chain the same way.
     *
     * Ordered by ICV rather than created_at, so the chain follows ZATCA's
     * sequential counter instead of wall-clock time, which is not deterministic
     * under concurrent inserts. Restricted to invoices that have been hashed —
     * an unsigned draft is not part of the chain. Null is correct for a
     * tenant's first invoice.
     *
     * The tenant scope is lifted because org_id is already pinned to this
     * invoice's own tenant. Leaving it on would make the answer depend on
     * whether a request context happens to be present, and the way it fails —
     * scoping to null, finding nothing, returning the genesis PIH — is silent
     * and indistinguishable from a genuinely first invoice.
     */
    public function getPreviousInvoiceHashAttribute(): ?string
    {
        // A draft holds no counter, so it holds no position, so it has no
        // predecessor to name. Asking anyway used to reach the query builder
        // as `where('icv', '<', null)`, which throws rather than returning
        // nothing — and every path that reads this attribute speculatively
        // died on it once the counter moved to issuance.
        if ($this->icv === null) {
            return null;
        }

        // The device's own predecessor: ZATCA chains per EGS unit, so a
        // branch's document never names head office's.
        return static::acrossTenants()
            ->where('org_id', $this->org_id)
            ->where('device_id', $this->device_id ?? $this->org_id)
            ->where('icv', '<', $this->icv)
            ->whereNotNull('hash')
            ->orderByDesc('icv')
            ->value('hash');
    }

    /**
     * The buyer name this document carries, preferring Arabic.
     *
     * Same rule as Organization::documentName(): Arabic is what the invoice
     * is required to be issued in, the Latin name is the fallback while the
     * column is being filled in, and an empty string is not a name.
     */
    public function documentBuyerName(): string
    {
        $arabic = trim((string) $this->buyer_name_ar);

        return $arabic !== '' ? $arabic : (string) $this->buyer_name;
    }
}
