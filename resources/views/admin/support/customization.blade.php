{{--
    A customization's agreed work: what is done, the price entered here (there
    is no price list), whether it is billed, and the features it switches on
    for the organization while released. Billed, its price is fixed.
--}}
<div class="space-y-3 rounded-card bg-surface p-4 border border-line shadow-card">
    <h2 class="text-sm font-semibold text-fg">Customization</h2>
    @if ($customization->invoice_id)
        <x-ui.alert tone="info">Billed <x-ui.date :value="$customization->billed_at" />: its price stays as invoiced.</x-ui.alert>
    @endif

    @admincan('billing.manage')
    <form method="POST" action="{{ route('admin.support.customization', $ticket->id) }}" class="space-y-3">
        @csrf
        <x-ui.field label="Work agreed" hint="What the customer reads as the work, beside their own words." optional>
            <x-ui.textarea name="description" rows="4" maxlength="5000">{{ old('description', $customization->description) }}</x-ui.textarea>
        </x-ui.field>
        <input type="hidden" name="billable" value="0">
        <x-ui.checkbox name="billable" value="1" :checked="(bool) old('billable', $customization->billable)">Billable</x-ui.checkbox>
        <div class="grid grid-cols-2 gap-3">
            <x-ui.field label="Price, excluding VAT" error="price" optional>
                <x-ui.input type="text" inputmode="decimal" name="price" dir="ltr" value="{{ old('price', $customization->price) }}" />
            </x-ui.field>
            <x-ui.field label="Currency" error="currency">
                <x-ui.select name="currency">
                    @foreach ($currencies as $code)
                        <option value="{{ $code }}" @selected(old('currency', $customization->currency) === $code)>{{ $code }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>
        <fieldset class="space-y-1">
            <legend class="text-xs text-muted">Features switched on once released</legend>
            @foreach ($features as $feature)
                <x-ui.checkbox name="features[]" :value="$feature->value" :checked="in_array($feature->value, (array) old('features', $customization->features ?? []), true)">{{ $feature->label() }}</x-ui.checkbox>
            @endforeach
        </fieldset>
        <x-ui.button variant="secondary" class="w-full">Save customization</x-ui.button>
    </form>
    @else
    <dl class="space-y-1 text-sm">
        <div><dt class="inline text-muted">Price:</dt> <dd class="inline">@if ($customization->price !== null)<x-ui.amount :value="$customization->price" :currency="$customization->currency" />@else - @endif</dd></div>
        <div><dt class="inline text-muted">Billable:</dt> <dd class="inline">{{ $customization->billable ? 'Yes' : 'No' }}</dd></div>
    </dl>
    @endadmincan
</div>
