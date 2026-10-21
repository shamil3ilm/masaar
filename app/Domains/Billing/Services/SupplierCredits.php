<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\SupplierCreditNote;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Credit notes from suppliers: what they take off one of the platform's
 * bills, recorded against that expense, never by changing it.
 *
 * A note is at the bill's own rate, so it undoes in the tax currency
 * exactly what the bill booked; its VAT takes back the share of input VAT
 * the bill deducted, and for reverse charge the self-assessed VAT on both
 * sides, in the month the note is dated (VAT Implementing Regulations Art
 * 54). What is owed goes down by the note; a bill already paid leaves the
 * supplier owing the platform the difference.
 */
class SupplierCredits
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  string  $net  what it takes off before VAT, in the bill's currency
     * @param  string  $vat  the VAT it takes off
     *
     * @throws DomainException saying why nothing was recorded
     */
    public function credit(Expense $expense, string $net, string $vat, CarbonImmutable $on, ?string $number, string $reason, ?string $actorId): SupplierCreditNote
    {
        return DB::transaction(function () use ($expense, $net, $vat, $on, $number, $reason, $actorId): SupplierCreditNote {
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->sole();

            if (! $expense->counted()) {
                throw new DomainException(__('A credit note is recorded against a bill that counts: this one is :status.', ['status' => mb_strtolower($expense->status->label())]));
            }

            if (! is_numeric($net) || ! is_numeric($vat) || (float) $net <= 0 || (float) $vat < 0) {
                throw new DomainException(__('A credit note takes off more than zero before VAT, and VAT of zero or more.'));
            }

            if ((float) $vat > 0 && ! $expense->vat_treatment->charged()) {
                throw new DomainException(__('VAT is entered only when the supplier charged it: :treatment has none.', ['treatment' => $expense->vat_treatment->label()]));
            }

            $gross = round((float) $net + (float) $vat, 2);
            $left = round((float) $expense->gross - (float) $expense->amount_credited - (float) $expense->amount_written_off, 2);

            if ($gross > $left + 0.005) {
                throw new DomainException(__('A credit note takes off no more than is left of the bill: :amount.', ['amount' => Receivable::money(max(0.0, $left)).' '.$expense->currency]));
            }

            if ($on->isAfter(now()->endOfDay()) || $on->lt(CarbonImmutable::parse($expense->expense_date->toDateString()))) {
                throw new DomainException(__('A credit note is dated on or after its bill, and not after today.'));
            }

            $factor = $expense->exchange_rate === null ? 1.0 : (float) $expense->exchange_rate;
            $netTax = round((float) $net * $factor, 2);
            $vatTax = round((float) $vat * $factor, 2);
            // The bill's own shares: as much of the VAT taken back as it deducted.
            $selfAssessed = (float) $expense->self_assessed_vat_tax > 0 && (float) $expense->net_tax > 0
                ? round($netTax * (float) $expense->self_assessed_vat_tax / (float) $expense->net_tax, 2) : 0.0;
            $base = (float) $expense->self_assessed_vat_tax > 0 ? (float) $expense->self_assessed_vat_tax : (float) $expense->vat_tax;
            $recoverable = $base > 0 ? round(($selfAssessed > 0 ? $selfAssessed : $vatTax) * (float) $expense->recoverable_vat_tax / $base, 2) : 0.0;

            $note = new SupplierCreditNote([
                'expense_id' => $expense->id,
                'supplier_id' => $expense->supplier_id,
                'credit_date' => $on->toDateString(),
                'supplier_credit_number' => $number === null || trim($number) === '' ? null : trim($number),
                'currency' => $expense->currency,
                'net' => Receivable::money((float) $net),
                'vat' => Receivable::money((float) $vat),
                'gross' => Receivable::money($gross),
                'exchange_rate' => $expense->exchange_rate,
                'net_tax' => Receivable::money($netTax),
                'vat_tax' => Receivable::money($vatTax),
                'gross_tax' => Receivable::money($netTax + $vatTax),
                'self_assessed_vat_tax' => Receivable::money($selfAssessed),
                'recoverable_vat_tax' => Receivable::money($recoverable),
                'reason' => mb_substr($reason, 0, 500),
            ]);
            $note->forceFill(['created_by' => $actorId])->save();

            $expense->amount_credited = Receivable::money((float) $expense->amount_credited + $gross);

            // Owed no longer: settled by the note.
            if ($expense->owed() && (float) $expense->balance() <= 0.0) {
                $expense->status = ExpenseStatus::Paid;
                $expense->paid_on = $on->toDateString();
            }

            $expense->save();

            $this->audit->logChange('finance.supplier_credit_note.recorded', 'SupplierCreditNote', (string) $note->id, null, [
                'expense_id' => (string) $expense->id, 'number' => $note->supplier_credit_number, 'gross' => (string) $note->gross,
                'currency' => $note->currency, 'recoverable_vat_tax' => (string) $note->recoverable_vat_tax, 'reason' => $note->reason,
            ], [], $actorId);

            return $note;
        });
    }
}
