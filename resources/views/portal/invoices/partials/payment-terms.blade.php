{{-- The terms the invoice is to be paid on, in words. --}}
<div>
    <x-ui.field :label="__('Payment terms')">
        <x-ui.input type="text" name="payment_terms" id="payment_terms" maxlength="1000" value="{{ old('payment_terms') }}" placeholder="{{ __('For example: 30 days net') }}" />
    </x-ui.field>
</div>
