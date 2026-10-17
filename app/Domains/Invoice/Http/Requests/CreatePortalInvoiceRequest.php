<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Requests;

use App\Domains\Invoice\Support\AdjustmentReason;
use App\Domains\Invoice\Support\ChargeRules;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * The same invoice rules, reached from a form instead of JSON.
 *
 * rules(), messages() and withValidator() are inherited - the organization's
 * country's InvoiceRules - so a rule added once reaches the API, the ERP
 * pipeline and this form together, which is the whole reason
 * PipelineSubmitRequest extends the same class rather than restating it.
 * The one rule added here narrows, never replaces: the form offers ZATCA's
 * listed reasons for a note, so it accepts only those.
 *
 * Only for a country whose portal form posts here (InvoiceRules::
 * portalRoute()). Any other is sent back to its own form before anything is
 * checked.
 *
 * What a form sends that JSON does not:
 *
 *   Every untouched input arrives as an empty string rather than absent. The
 *   parent fills its defaults with `??`, which does not catch '', so an
 *   untouched currency field would reach `size:3` as '' and fail a rule the
 *   taxpayer never saw. Blanks become null first, and then the parent's
 *   defaults work as written.
 *
 *   A line repeater posts the rows it is holding, including the empty one
 *   waiting to be filled in. Submitted as-is that row fails
 *   `lines.*.description` required, so adding a row and not using it would
 *   refuse the invoice.
 *
 *   The buyer address is six inputs that are always present. All six empty is
 *   a simplified invoice with no address, not an address made of empty
 *   strings - and left as strings, country_code would fail `size:2`.
 */
class CreatePortalInvoiceRequest extends CreateInvoiceRequest
{
    /**
     * The inputs that say a line row was actually used.
     *
     * A row is kept if any of them carries something. Keeping a partly filled
     * row is deliberate: the taxpayer started it, so the right answer is the
     * validation message for what is missing, not silently dropping their
     * work.
     *
     * @var list<string>
     */
    private const LINE_FIELDS = ['description', 'quantity', 'unit_price'];

    /**
     * The parent's rules, with the reason held to the list the form offers.
     *
     * The API keeps free text (see AdjustmentReason). The form is a select,
     * so anything else arriving from it was not chosen from the list.
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['adjustment_reason'][] = Rule::in(AdjustmentReason::values());

        return $rules;
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'adjustment_reason.in' => __('Choose one of the reasons ZATCA lists for a credit or debit note.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        // A country whose portal form posts somewhere else writes its
        // invoices there: checked by these rules, it would be refused for
        // fields the form never asked for, or drafted without its own.
        if ($this->invoiceRules()->portalRoute() !== $this->route()?->getName()) {
            throw new HttpResponseException(redirect()->route('portal.invoices.create')
                ->with('error', __('Write this invoice on the form below: it asks for what your country\'s invoices need.')));
        }

        $this->merge([
            'lines' => $this->usedLines(),
            'buyer_address' => $this->addressOrNull(),
            // The charge rows used, as the lines are.
            'charges' => ChargeRules::used($this->input('charges')),
            // The prepayment invoices settled, typed as their numbers
            // separated by commas.
            'prepayments' => $this->prepaymentNumbers(),
        ]);

        $this->replace($this->withoutBlanks($this->all()));

        // After the blanks are gone, so `??` sees absent rather than ''.
        parent::prepareForValidation();
    }

    /**
     * The line rows the taxpayer put something in.
     *
     * @return array<int, mixed>
     */
    private function usedLines(): array
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return [];
        }

        $used = array_filter($lines, function ($line): bool {
            if (! is_array($line)) {
                return false;
            }

            foreach (self::LINE_FIELDS as $field) {
                if (trim((string) ($line[$field] ?? '')) !== '') {
                    return true;
                }
            }

            return false;
        });

        // Renumbered, because the rules address lines.*.description by
        // position and a gap in the keys makes the array an object in the
        // error bag - so the message would arrive against a field the form
        // has no input for. A line's carried charges are kept the same way.
        return array_map(function (array $line): array {
            $charges = ChargeRules::used($line['charges'] ?? null);
            unset($line['charges']);

            return $charges === null ? $line : [...$line, 'charges' => $charges];
        }, array_values($used));
    }

    /**
     * "ADV-1, ADV-2" as the prepayments the rules take, or null for none.
     *
     * @return list<array{invoice_number: string}>|null
     */
    private function prepaymentNumbers(): ?array
    {
        $numbers = array_values(array_filter(array_map('trim', explode(',', (string) $this->input('prepayment_numbers', '')))));

        return $numbers === [] ? null : array_map(fn (string $number): array => ['invoice_number' => $number], $numbers);
    }

    /**
     * The buyer address, or null when every part of it is empty.
     *
     * @return array<string, mixed>|null
     */
    private function addressOrNull(): ?array
    {
        $address = $this->input('buyer_address');

        if (! is_array($address)) {
            return null;
        }

        foreach ($address as $value) {
            if (! is_array($value) && trim((string) $value) !== '') {
                return $address;
            }
        }

        return null;
    }

    /**
     * The input with empty strings removed, at every depth.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private function withoutBlanks(array $input): array
    {
        $kept = [];

        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $kept[$key] = $this->withoutBlanks($value);

                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            $kept[$key] = $value;
        }

        return $kept;
    }
}
