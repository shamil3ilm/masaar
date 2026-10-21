{{-- The terms the invoice is to be paid on, in words. $arabicHint: where
     the country requires them in Arabic, said beside the label. --}}
<div>
    <x-ui.field :label="__('Payment terms')" error="payment_terms" :hint="$arabicHint ?? null">
        <x-ui.input type="text" name="payment_terms" id="payment_terms" maxlength="1000" value="{{ old('payment_terms') }}" placeholder="{{ __('For example: 30 days net') }}" />
    </x-ui.field>
</div>
