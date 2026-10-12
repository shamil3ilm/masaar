<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Requests;

/**
 * The same invoice rules, reached from a form instead of JSON.
 *
 * Only prepareForValidation() is overridden. rules(), messages() and
 * withValidator() are deliberately inherited, so a ZATCA rule added once
 * reaches the API, the ERP pipeline and this form together - which is the
 * whole reason PipelineSubmitRequest extends the same class rather than
 * restating it.
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

    protected function prepareForValidation(): void
    {
        $this->merge([
            'lines' => $this->usedLines(),
            'buyer_address' => $this->addressOrNull(),
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
        // has no input for.
        return array_values($used);
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
