{{-- The terms the invoice is to be paid on, in words. --}}
<div>
    <label for="payment_terms" class="block text-sm font-medium text-fg">{{ __('Payment terms') }}</label>
    <x-ui.input type="text" name="payment_terms" id="payment_terms" maxlength="1000" value="{{ old('payment_terms') }}" placeholder="{{ __('For example: 30 days net') }}" />
</div>
