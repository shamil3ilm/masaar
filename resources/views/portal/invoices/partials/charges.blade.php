{{--
    The charges on the whole invoice: delivery, a service charge,
    packaging. Rows are added from a <template> by resources/js/
    invoice-charges.js, as lines are; with no script the form still takes
    the charges it came back with. A row left empty is dropped.

    @param list<array<string, mixed>> $chargePresets  ChargePresets::for()
    @param array<string, string>      $chargeCategories  code => label
    @param string                     $standardRate
--}}
{{-- Optional: folded away on a phone until wanted, opened by an error inside. --}}
<x-ui.section :title="__('Charges')" collapse class="mb-6" data-charges>

    <div data-charge-rows class="space-y-4">
        @foreach ((array) old('charges', []) as $index => $row)
            @include('portal.invoices.partials.charge-row', ['index' => $index, 'row' => (array) $row])
        @endforeach
    </div>

    <template data-charge-template>
        @include('portal.invoices.partials.charge-row', ['index' => 0, 'row' => []])
    </template>

    <x-ui.button type="button" variant="secondary" data-add-charge class="mt-4">{{ __('Add charge') }}</x-ui.button>
</x-ui.section>
