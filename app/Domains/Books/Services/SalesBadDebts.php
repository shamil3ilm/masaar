<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Books\Models\BadDebtRelief;
use App\Domains\Books\Models\InvoicePayment;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceAdjustments;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchScope;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Bad-debt relief: the output tax on a sale the customer never paid for,
 * taken back once the country's conditions are met (its BadDebtRules), and
 * given back when the customer pays after all.
 *
 *   KSA VAT Implementing Regulations Art 40(7): the tax was declared on a
 *   return and paid; the customer is not a related person; at least twelve
 *   months have passed since the supply; a certified accountant certifies
 *   the write-off; above SAR 100,000 owed by the customer, formal recovery
 *   has failed. Art 40(9): tax on a later payment is due in the period it is
 *   received - with a new tax invoice for it, which the books draft for the
 *   person to issue (recover()).
 *
 *   UAE Federal Decree-Law No. 8 of 2017 Art 64(1): the tax was charged and
 *   paid; the consideration is written off in the accounts; more than six
 *   months have passed since the supply; the customer was notified. The
 *   texts here say nothing of a later payment; it is treated as Art 40(9)
 *   does, the tax on it due again.
 *
 * "Declared and paid" is read as: the supply's tax point falls in a VAT
 * period marked filed (VatPeriods). The relief counts in the period of the
 * day the last condition was met - or the next open one.
 */
class SalesBadDebts
{
    public function __construct(
        private readonly BooksCountry $country,
        private readonly Receivables $receivables,
        private readonly VatPeriods $periods,
        private readonly AuditService $audit,
        private readonly BranchScope $branches,
        private readonly InvoiceAdjustments $adjustments,
        private readonly InvoiceDrafter $drafter,
    ) {}

    /** @return Collection<int, BadDebtRelief> */
    public function of(Invoice $invoice): Collection
    {
        return BadDebtRelief::acrossTenants()->where('invoice_id', $invoice->id)->orderBy('happened_on')->orderBy('created_at')->get();
    }

    /** What is written off and not yet paid after all, in the invoice's currency. */
    public function active(Invoice $invoice): float
    {
        return round($this->of($invoice)->sum(fn (BadDebtRelief $row): float => $row->signed()), 2);
    }

    /**
     * @param  array{written_off_on: CarbonImmutable, notified_on?: CarbonImmutable|null, certificate?: string|null, evidence?: string|null, unrelated?: bool}  $claim
     *
     * @throws DomainException saying which condition is not met
     */
    public function claim(Organization $organization, Invoice $invoice, array $claim): BadDebtRelief
    {
        $rules = $this->country->of($organization)->vatReturn()->badDebtRules()
            ?? throw new DomainException(__('Bad-debt relief is not part of this country\'s VAT return.'));
        $organizationId = (string) $organization->id;
        $writtenOff = $claim['written_off_on'];
        $notified = $claim['notified_on'] ?? null;

        if (! in_array($invoice->document_type, [DocumentType::Invoice, DocumentType::DebitNote], true) || $this->active($invoice) > 0) {
            throw new DomainException(__('Relief is claimed once, on an invoice or debit note.'));
        }

        $supply = CarbonImmutable::parse($invoice->supply_date !== null && $invoice->supply_date->lt($invoice->issue_date) ? $invoice->supply_date : $invoice->issue_date);

        if ($writtenOff->lt($supply->addMonthsNoOverflow($rules->months))) {
            throw new DomainException(__('Relief needs :months months from the supply, :date; it can be claimed from :from (:rule).', [
                'months' => $rules->months, 'date' => $supply->toDateString(), 'from' => $supply->addMonthsNoOverflow($rules->months)->toDateString(), 'rule' => $rules->citation,
            ]));
        }

        if ($this->periods->filedOn($organizationId, $supply) === null) {
            throw new DomainException(__('The tax on it must have been declared on a return you filed: mark the VAT period of :date filed first (:rule).', ['date' => $supply->toDateString(), 'rule' => $rules->citation]));
        }

        if ($rules->needsNotice && $notified === null) {
            throw new DomainException(__('Say when the customer was told of the write-off (:rule).', ['rule' => $rules->citation]));
        }

        if ($rules->needsCertificate && blank($claim['certificate'] ?? null)) {
            throw new DomainException(__('Give the reference of your certified accountant\'s certificate of the write-off (:rule).', ['rule' => $rules->citation]));
        }

        if ($rules->needsUnrelated && ! ($claim['unrelated'] ?? false)) {
            throw new DomainException(__('Relief is only for a customer who is not a related person (:rule).', ['rule' => $rules->citation]));
        }

        $happened = $notified !== null && $notified->gt($writtenOff) ? $notified : $writtenOff;
        $this->periods->assertOpen($organizationId, $happened, __('The write-off'));

        // What is left on it after every credit note - a note on several
        // invoices by its share (Receivables::spread) - so output tax a note
        // already took back is never relieved again.
        $row = $this->receivables->balanceOf($invoice, $happened);
        $unpaid = (float) ($row['balance'] ?? 0.0);

        if ($unpaid <= 0.005) {
            throw new DomainException(__('Nothing is owed on this invoice as at :date.', ['date' => $happened->toDateString()]));
        }

        if ($rules->evidenceAbove !== null && blank($claim['evidence'] ?? null)) {
            if ($this->owedByCustomer($organization, $row, $happened) > $rules->evidenceAbove) {
                throw new DomainException(__('This customer owes more than :limit: give the evidence that formal recovery failed, such as a judgment or bankruptcy (:rule).', [
                    'limit' => number_format($rules->evidenceAbove), 'rule' => $rules->citation,
                ]));
            }
        }

        $relief = BadDebtRelief::query()->create([
            'org_id' => $organizationId,
            'invoice_id' => (string) $invoice->id,
            'kind' => BadDebtRelief::RELIEF,
            'happened_on' => $happened->toDateString(),
            'amount' => number_format($unpaid, 2, '.', ''),
            'written_off_on' => $writtenOff->toDateString(),
            'notified_on' => $notified?->toDateString(),
            'certificate_reference' => ($certificate = trim((string) ($claim['certificate'] ?? ''))) === '' ? null : mb_substr($certificate, 0, 200),
            'evidence_reference' => ($evidence = trim((string) ($claim['evidence'] ?? ''))) === '' ? null : mb_substr($evidence, 0, 200),
            'unrelated_confirmed' => (bool) ($claim['unrelated'] ?? false),
        ]);

        $this->audit->logChange('books.bad_debt.relieved', 'BadDebtRelief', (string) $relief->id, null,
            ['invoice_id' => (string) $invoice->id, 'amount' => (string) $relief->amount, 'happened_on' => $happened->toDateString()], [], null, $organizationId);

        return $relief;
    }

    /**
     * What the customer owes the organization as at the day, every branch's
     * invoices - the limit is the taxable person's, not the branch
     * switcher's - in the return's currency, since the limit is (SAR
     * 100,000): each balance at its invoice's rate. A balance with no rate
     * cannot be weighed, so it counts as above the limit.
     *
     * @param  array<string, mixed>  $row
     */
    private function owedByCustomer(Organization $organization, array $row, CarbonImmutable $happened): float
    {
        $currency = $this->country->currency($organization);
        $rows = $this->branches->within(null, fn (): Collection => $this->receivables->asAt((string) $organization->id, $happened, $row['customer_id'], open: true))
            ->filter(fn (array $other): bool => $row['customer_id'] !== null || $other['customer'] === $row['customer']);
        $owed = 0.0;

        foreach ($rows as $other) {
            $rate = $other['currency'] === $currency ? 1.0 : (float) ($other['invoice']?->exchange_rate ?? 0);

            // A credit with no rate only lowers what is owed: left out.
            if ($rate <= 0) {
                if ($other['balance'] >= 0.005) {
                    return INF;
                }

                continue;
            }

            $owed += $other['balance'] * $rate;
        }

        return $owed;
    }

    /**
     * The customer paid after the relief: the tax on what came in is due
     * again, in the period of the payment (KSA Art 40(9)).
     *
     * Where the country also asks for "a new Tax invoice ... to reflect the
     * additional amount received" (BadDebtRules::newInvoiceOnPayment), it is
     * drafted for the person to issue, for what was recovered, linked to
     * this recovery and the invoice (InvoiceAdjustments::recoveryInvoice).
     * A draft the country's rules refuse - the original lacks what a new
     * invoice must carry - does not undo the payment: it is logged, and the
     * sale's page says the invoice is still to be written.
     */
    public function recover(Invoice $invoice, float $amount, CarbonImmutable $paidOn, ?InvoicePayment $payment = null): ?BadDebtRelief
    {
        $active = $this->active($invoice);
        $recovered = round(min($amount, $active), 2);

        if ($recovered <= 0) {
            return null;
        }

        $relief = $this->of($invoice)->last(fn (BadDebtRelief $row): bool => $row->kind === BadDebtRelief::RELIEF);

        $recovery = BadDebtRelief::query()->create([
            'org_id' => (string) $invoice->org_id,
            'invoice_id' => (string) $invoice->id,
            'kind' => BadDebtRelief::RECOVERY,
            'relief_id' => $relief?->id,
            'invoice_payment_id' => $payment?->id,
            'happened_on' => $paidOn->toDateString(),
            'amount' => number_format($recovered, 2, '.', ''),
        ]);

        $organization = Organization::query()->find($invoice->org_id);

        if ($organization !== null && $this->country->of($organization)->vatReturn()->badDebtRules()?->newInvoiceOnPayment === true) {
            try {
                $this->adjustments->recoveryInvoice($organization, $invoice, (string) $recovery->amount, (string) $recovery->id, $paidOn);
            } catch (ValidationException|DomainException $e) {
                Log::warning('No tax invoice drafted for a payment after bad-debt relief', [
                    'invoice_id' => (string) $invoice->id,
                    'recovery_id' => (string) $recovery->id,
                    'errors' => $e instanceof ValidationException ? $e->errors() : [$e->getMessage()],
                ]);
            }
        }

        return $recovery;
    }

    /**
     * A payment after the relief was reversed - a cheque bounced, a payment
     * recorded in error - so the money never came: its recovery is undone
     * on the day of the reversal, and what it gave back stands written off
     * again, its output tax taken back again in that period.
     *
     * The tax invoice drafted for the recovery (KSA Art 40(9)) is discarded
     * while still a draft. Once issued it stands on the authority's record,
     * and only a credit note against it takes it back: the person is told
     * to issue one, and since that note takes the tax back in the return,
     * this undoing is marked to count through it (by_credit_note), not as
     * well.
     */
    public function undoRecovery(Invoice $invoice, InvoicePayment $payment, InvoicePayment $reversal): ?BadDebtRelief
    {
        $recovery = $this->of($invoice)->first(fn (BadDebtRelief $row): bool => $row->kind === BadDebtRelief::RECOVERY && $row->invoice_payment_id === (string) $payment->id);

        if ($recovery === null) {
            return null;
        }

        $drafted = $this->recoveryInvoices($invoice)[(string) $recovery->id] ?? null;
        $issued = $drafted !== null && ! $drafted->isEditable();

        if ($drafted !== null && ! $issued) {
            $this->drafter->discard($drafted);
        }

        $undone = BadDebtRelief::query()->create([
            'org_id' => (string) $invoice->org_id,
            'invoice_id' => (string) $invoice->id,
            'kind' => BadDebtRelief::REVERSED,
            'relief_id' => (string) $recovery->id,
            'invoice_payment_id' => (string) $reversal->id,
            'happened_on' => $reversal->paid_on->toDateString(),
            'amount' => (string) $recovery->amount,
            'by_credit_note' => $issued,
        ]);

        $this->audit->logChange('books.bad_debt.recovery_reversed', 'BadDebtRelief', (string) $undone->id, null, [
            'invoice_id' => (string) $invoice->id,
            'recovery_id' => (string) $recovery->id,
            'amount' => (string) $undone->amount,
            'tax_invoice' => $drafted === null ? null : ($issued ? 'issued: a credit note against '.$drafted->invoice_number.' is needed' : 'draft discarded'),
        ], [], null, (string) $invoice->org_id);

        return $undone;
    }

    /**
     * The tax invoices drafted for payments after the relief, by the
     * recovery each documents (recover()).
     *
     * @return array<string, Invoice>
     */
    public function recoveryInvoices(Invoice $invoice): array
    {
        $found = [];

        foreach ($this->adjustments->of($invoice) as $adjustment) {
            if ($adjustment->bad_debt_relief_id !== null && $adjustment->newInvoice !== null) {
                $found[(string) $adjustment->bad_debt_relief_id] = $adjustment->newInvoice;
            }
        }

        return $found;
    }

    /**
     * What the period's return takes: each relief (share above zero) and
     * recovery (below zero) counting in it, as the part of the invoice's
     * total it is.
     *
     * @return list<array{invoice: Invoice, share: float, kind: string}>
     */
    public function rows(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        $candidates = BadDebtRelief::acrossTenants()
            ->where('org_id', $organizationId)
            ->where('happened_on', '<', $to->addDay()->toDateString())
            ->get();
        $invoices = Invoice::acrossTenants()->whereIn('id', $candidates->pluck('invoice_id')->unique())->with(['lines', 'charges', 'prepayments', 'branch' => fn ($branch) => $branch->acrossTenants(), 'org'])->get()->keyBy('id');

        foreach ($candidates as $row) {
            $counts = $this->periods->openDay($organizationId, CarbonImmutable::parse($row->happened_on));
            $invoice = $invoices[$row->invoice_id] ?? null;

            // An undoing that counts through the credit note against an
            // issued tax invoice (undoRecovery()) is not counted again here.
            if ($invoice === null || $row->by_credit_note || $counts->lt($from) || $counts->gt($to) || (float) $invoice->total <= 0) {
                continue;
            }

            $rows[] = [
                'invoice' => $invoice,
                'share' => $row->signed() / (float) $invoice->total,
                'kind' => $row->kind,
            ];
        }

        return $rows;
    }
}
