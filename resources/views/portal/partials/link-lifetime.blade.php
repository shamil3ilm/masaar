{{-- How long an invoice link lasts: never, or one of InvoiceLinks::LIFETIMES. --}}
<x-ui.select name="lifetime">
    <option value="never" @selected($selected === 'never')>{{ __('Never') }}</option>
    @foreach (\App\Domains\Invoice\Services\InvoiceLinks::LIFETIMES as $days)
        <option value="{{ $days }}" @selected($selected === (string) $days)>{{ __('After :count days', ['count' => $days]) }}</option>
    @endforeach
</x-ui.select>
