{{--
    The charges on the whole invoice: delivery, a service charge,
    packaging. Rows are added from a <template> by resources/js/
    invoice-charges.js, as lines are; with no script the form still takes
    the charges it came back with. A row left empty is dropped.

    @param list<array<string, mixed>> $chargePresets  ChargePresets::for()
    @param array<string, string>      $chargeCategories  code => label
    @param string                     $standardRate
--}}
<div class="mb-6 rounded-lg bg-white dark:bg-gray-900 p-6 shadow" data-charges>
    <div class="mb-4 flex items-baseline justify-between">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Charges') }}</h3>
        <x-ui.button type="button" variant="outline" data-add-charge>{{ __('Add charge') }}</x-ui.button>
    </div>

    <div data-charge-rows class="space-y-4">
        @foreach ((array) old('charges', []) as $index => $row)
            @include('portal.invoices.partials.charge-row', ['index' => $index, 'row' => (array) $row])
        @endforeach
    </div>

    <template data-charge-template>
        @include('portal.invoices.partials.charge-row', ['index' => 0, 'row' => []])
    </template>
</div>
