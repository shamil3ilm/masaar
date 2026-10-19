{{--
    Split with insurer: the sale on this form billed in two shares, the
    patient's and the insurer's, each an invoice of its own (InsuranceSplits).
    Saved by its own button, which posts the same form to the split route,
    so the form saves an ordinary invoice exactly as it did.

    Open from the start where the business type bills insurers - a pharmacy,
    a clinic - and folded for everyone else, who can still open it.

    @param \Illuminate\Support\Collection $insurers  the customers marked as insurers
    @param \App\Domains\Organization\DTOs\BusinessType|null $businessType
--}}
@php($method = old('split.method', 'percent'))
<x-ui.section :title="__('Split with insurer')" :closed="! ($businessType?->insurerSplit ?? false) && old('split') === null" class="mb-6" data-split-sale
              :description="__('Bill the patient\'s share and the insurer\'s share of this sale on two linked invoices, one to each.')">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    <option value="percent" @selected($method === 'percent')>{{ __('A percentage of each line') }}</option>
                    <option value="amount" @selected($method === 'amount')>{{ __('A fixed amount') }}</option>
                    <option value="lines" @selected($method === 'lines')>{{ __('Some lines, the insurer the others') }}</option>
                </x-ui.select>
            </x-ui.field>
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
            <p class="mt-1 text-xs text-muted">{{ __('For a fixed amount, such as a deductible. It is spread over the lines.') }}</p>
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

    <p class="mt-4 max-w-3xl text-sm text-muted">{{ __('The buyer above is the patient. Each share is its own invoice, with its own number, and the insurer\'s carries the claim, policy and member numbers. A split sale takes no discount or charges: enter the prices after them.') }}</p>

    <div class="mt-4">
        <x-ui.button variant="secondary" formaction="{{ route('portal.invoices.splits.store') }}">{{ __('Save as split drafts') }}</x-ui.button>
    </div>
</x-ui.section>
