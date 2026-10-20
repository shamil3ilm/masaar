<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Requests;

use App\Domains\Invoice\Http\Concerns\SplitRules;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Support\ShareAllocation;

/**
 * The new-invoice form, saved as a sale an insurer pays for: the same form
 * and the same rules (CreatePortalInvoiceRequest), with the split beside
 * them (SplitRules). Its own button posts it here.
 *
 * Split by line, the form names the insurer's lines by number - "1, 3" -
 * as it names the prepayments it settles; every other line is the
 * patient's.
 */
class CreatePortalSplitRequest extends CreatePortalInvoiceRequest
{
    use SplitRules;

    public function rules(): array
    {
        return [...parent::rules(), ...$this->splitRules()];
    }

    public function messages(): array
    {
        return [...parent::messages(), ...$this->splitMessages()];
    }

    /** The invoice form, which this rides on. */
    protected function formRoute(): ?string
    {
        return 'portal.invoices.store';
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge([...$this->splitDefaults(), 'lines' => $this->payers()]);
    }

    /**
     * Each used line with its payer, when split by line.
     *
     * @return array<int, mixed>
     */
    private function payers(): array
    {
        $lines = (array) $this->input('lines', []);

        if ($this->input('split.method') !== 'lines' || $this->input('split.recipient') === InsuranceSplit::INSURER) {
            return $lines;
        }

        $insurer = array_map('intval', array_filter(array_map('trim', explode(',', (string) $this->input('insurer_lines', ''))), 'is_numeric'));

        return array_map(
            fn ($line, int $index) => is_array($line) ? [...$line, 'payer' => in_array($index + 1, $insurer, true) ? ShareAllocation::INSURER : ShareAllocation::PATIENT] : $line,
            array_values($lines),
            array_keys(array_values($lines)),
        );
    }
}
