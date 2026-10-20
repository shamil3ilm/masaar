{{--
    Bill with insurer: the sale on this form as one an insurer pays for,
    wholly or in part (InsuranceSplits). One invoice, in the name of the
    recipient: the patient - the buyer above - with the amounts for the
    account of the insurer and of the patient; or the insurer, when it
    contracted for the care and the patient pays nothing, as the country's
    healthcare VAT guidance has it (InsuranceSplits). Saved by its own button,
    which posts the same form to its own route, so the form saves an ordinary
    invoice exactly as it did.

    Open from the start where the business type bills insurers - a pharmacy,
    a clinic - and folded for everyone else, who can still open it.

    @param \Illuminate\Support\Collection $insurers  the customers marked as insurers
    @param \App\Domains\Organization\DTOs\BusinessType|null $businessType
--}}
@php($method = old('split.method', 'percent'))
@php($recipient = old('split.recipient', 'patient'))
<x-ui.section :title="__('Split with insurer')" :closed="! ($businessType?->insurerSplit ?? false) && old('split') === null" class="mb-6" data-split-sale
              :description="__('A sale an insurer pays for, wholly or in part: one invoice to the recipient, stating what is for the account of the insurer and of the patient.')">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="sm:col-span-2 lg:col-span-3">
            <x-ui.field :label="__('Recipient')">
                <x-ui.select name="split[recipient]" id="split_recipient">
                    <option value="patient" @selected($recipient === 'patient')>{{ __('The patient - the insurer pays part or all of their bill') }}</option>
                    <option value="insurer" @selected($recipient === 'insurer')>{{ __('The insurer - it contracted for the care, and the patient pays nothing') }}</option>
                </x-ui.select>
            </x-ui.field>
            <p class="mt-1 text-xs text-muted">{{ __('The patient is the recipient unless the insurer contracted with you for the care and the patient neither contracts nor pays. A patient who pays a share is the recipient.') }}</p>
        </div>

        <div>
            <x-ui.field :label="__('Insurer')">
                <x-ui.select name="split[insurer_customer_id]" id="split_insurer">
                    <option value="">{{ __('Choose the insurer...') }}</option>
                    @foreach ($insurers as $insurer)
                        <option value="{{ $insurer->id }}" @selected(old('split.insurer_customer_id') === $insurer->id)>{{ $insurer->name }}{{ $insurer->vat_number ? ' - '.$insurer->vat_number : '' }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <p class="mt-1 text-xs text-muted">
                {{ __('A customer marked as an insurer, with its VAT number and address.') }}
                <a href="{{ route('portal.customers.create') }}" class="font-medium underline underline-offset-4">{{ __('Add an insurer') }}</a>
            </p>
        </div>

        <div>
            <x-ui.field :label="__('The patient pays')">
                <x-ui.select name="split[method]" id="split_method">
                    <option value="percent" @selected($method === 'percent')>{{ __('A percentage of the bill') }}</option>
                    <option value="amount" @selected($method === 'amount')>{{ __('A fixed amount') }}</option>
                    <option value="lines" @selected($method === 'lines')>{{ __('Some lines, the insurer the others') }}</option>
                </x-ui.select>
            </x-ui.field>
            <p class="mt-1 text-xs text-muted">{{ __('When the patient is the recipient.') }}</p>
        </div>

        <div>
            <x-ui.field :label="__('Patient\'s percentage')">
                <x-ui.input type="number" step="0.01" min="0" max="100" name="split[percent]" id="split_percent" value="{{ old('split.percent') }}" />
            </x-ui.field>
            <p class="mt-1 text-xs text-muted">{{ __('For a percentage, such as 20 for a 20% co-payment.') }}</p>
        </div>

        <div>
            <x-ui.field :label="__('Patient\'s amount')">
                <x-ui.input type="number" step="0.01" min="0" name="split[amount]" id="split_amount" value="{{ old('split.amount') }}" />
            </x-ui.field>
            <p class="mt-1 text-xs text-muted">{{ __('For a fixed amount with VAT, such as a deductible.') }}</p>
        </div>

        <div>
            <x-ui.field :label="__('Insurer\'s lines')">
                <x-ui.input type="text" name="insurer_lines" id="insurer_lines" value="{{ old('insurer_lines') }}" placeholder="1, 3" />
            </x-ui.field>
            <p class="mt-1 text-xs text-muted">{{ __('For a split by line: the numbers of the lines the insurer pays, separated by commas. The patient pays the others.') }}</p>
        </div>

        <div>
            <x-ui.field :label="__('Claim or approval number')">
                <x-ui.input type="text" name="split[claim_reference]" id="split_claim" maxlength="127" value="{{ old('split.claim_reference') }}" />
            </x-ui.field>
        </div>

        <div>
            <x-ui.field :label="__('Policy number')">
                <x-ui.input type="text" name="split[policy_number]" id="split_policy" maxlength="127" value="{{ old('split.policy_number') }}" />
            </x-ui.field>
        </div>

        <div>
            <x-ui.field :label="__('Member number')">
                <x-ui.input type="text" name="split[member_id]" id="split_member" maxlength="127" value="{{ old('split.member_id') }}" />
            </x-ui.field>
        </div>
    </div>

    <p class="mt-4 max-w-3xl text-sm text-muted">{{ __('The buyer above is the patient. Their invoice states the insurer\'s share and the references, and is the copy the insurer is sent; a citizen\'s private healthcare on it is zero-rated with their national ID. Enter a price agreed with the insurer as the price: VAT is on it. No document discount or charges.') }}</p>

    <div class="mt-4">
        <x-ui.button variant="secondary" formaction="{{ route('portal.invoices.splits.store') }}">{{ __('Save with the insurer\'s share') }}</x-ui.button>
    </div>
</x-ui.section>
