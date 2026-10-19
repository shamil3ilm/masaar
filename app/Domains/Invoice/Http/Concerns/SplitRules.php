<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Concerns;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Support\ShareAllocation;
use App\Domains\Organization\Rules\VatNumber;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Validation\Rule;

/**
 * The split beside a sale: how it is divided between the patient and the
 * insurer, who the insurer is, and the insurer's references - for a request
 * that checks the sale itself by the country's invoice rules
 * (CreateInvoiceRequest and those extending it).
 *
 * The sale is the patient's document, so it is checked as the country's
 * patient share is (SplitBilling::patientType()). It is an invoice, and
 * takes no document discount, charge or prepayment: those would have to be
 * divided too, and enter the price after them instead.
 */
trait SplitRules
{
    /**
     * @return array<string, mixed>
     */
    protected function splitDefaults(): array
    {
        return [
            'type' => $this->jurisdiction()->splitBilling()->patientType(),
            'document_type' => DocumentType::Invoice->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function splitRules(): array
    {
        $organizationId = app(TenantResolver::class)->getOrganizationId();

        return [
            'split' => ['required', 'array', function (string $attribute, mixed $value, \Closure $fail): void {
                // A country that bills no sale in two shares says why.
                if (($reason = $this->jurisdiction()->splitBilling()->unavailable()) !== null) {
                    $fail($reason);
                }
            }],
            'split.method' => ['required', Rule::in(InsuranceSplit::METHODS)],
            'split.percent' => ['nullable', 'required_if:split.method,percent', 'numeric', 'gt:0', 'lt:100', 'decimal:0,2'],
            'split.amount' => ['nullable', 'required_if:split.method,amount', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            // A saved insurer, or one given in full.
            'split.insurer_customer_id' => ['nullable', 'string', 'uuid', 'required_without:split.insurer',
                Rule::exists('customers', 'id')->where('org_id', $organizationId)->where('is_insurer', true)->whereNull('deleted_at')],
            'split.insurer' => ['nullable', 'array'],
            'split.insurer.name' => ['required_with:split.insurer', 'string', 'max:255'],
            'split.insurer.name_ar' => ['nullable', 'string', 'max:255'],
            'split.insurer.vat_number' => ['nullable', 'string', new VatNumber],
            'split.insurer.buyer_id' => ['nullable', 'string', 'max:50'],
            'split.insurer.buyer_id_scheme' => ['nullable', 'string', 'max:10'],
            'split.insurer.address' => ['nullable', 'array'],
            'split.insurer.address.street' => ['nullable', 'string', 'max:255'],
            'split.insurer.address.building_number' => ['nullable', 'string', 'max:20'],
            'split.insurer.address.district' => ['nullable', 'string', 'max:100'],
            'split.insurer.address.city' => ['nullable', 'string', 'max:100'],
            'split.insurer.address.postal_code' => ['nullable', 'string', 'max:10'],
            'split.insurer.address.country_code' => ['nullable', 'string', 'size:2'],
            // On the insurer's invoice: the claim as its order reference
            // (BT-13), the policy as its contract (BT-12) and the member as
            // the buyer's own reference (BT-10).
            'split.claim_reference' => ['nullable', 'string', 'max:127'],
            'split.policy_number' => ['nullable', 'string', 'max:127'],
            'split.member_id' => ['nullable', 'string', 'max:127'],
            'lines.*.payer' => ['nullable', 'required_if:split.method,lines', Rule::in([ShareAllocation::PATIENT, ShareAllocation::INSURER])],
            'document_type' => ['required', Rule::in([DocumentType::Invoice->value])],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:0'],
            'charges' => ['prohibited'],
            'prepayments' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function splitMessages(): array
    {
        return [
            'split.required' => __('Say how the sale is split between the patient and the insurer.'),
            'split.insurer_customer_id.required_without' => __('Name the insurer: one of your customers marked as an insurer, or its details.'),
            'split.insurer_customer_id.exists' => __('Choose one of your customers marked as an insurer.'),
            'lines.*.payer.required_if' => __('Split by line: say who pays each line, the patient or the insurer.'),
            'document_type.in' => __('A sale is split on an invoice; correct a share with a note against it.'),
            'discount_amount.max' => __('A split sale takes no document discount. Enter the prices after it.'),
            'charges.prohibited' => __('A split sale takes no document charges. Enter the prices with them.'),
            'prepayments.prohibited' => __('A split sale settles no prepayments.'),
        ];
    }
}
