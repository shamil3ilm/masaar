{{--
    What the platform owes its suppliers by how long past due, in one
    currency (Payables::ageing): a row per supplier and the totals.

    table     ['rows' => [...], 'totals' => [...]]
    headings  bucket => heading
    currency  the table's currency
--}}
<x-ui.table :primary="[0, 6]">
    <x-slot:head>
        <x-ui.th>{{ __('Supplier') }}</x-ui.th>
        @foreach ($headings as $heading)
            <x-ui.th align="right">{{ $heading }}</x-ui.th>
        @endforeach
        <x-ui.th align="right">{{ __('Total (:currency)', ['currency' => $currency]) }}</x-ui.th>
    </x-slot:head>
    @foreach ($table['rows'] as $row)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-3 text-sm font-medium text-fg"><a href="{{ route('admin.finance.suppliers.show', $row['supplier_id']) }}" class="text-accent hover:underline">{{ $row['name'] }}</a></td>
            @foreach (array_keys($headings) as $bucket)
                <td class="px-6 py-3 text-right text-sm tabular-nums text-fg"><x-ui.amount :value="$row[$bucket]" /></td>
            @endforeach
            <td class="px-6 py-3 text-right text-sm font-semibold tabular-nums text-fg"><x-ui.amount :value="$row['total']" /></td>
        </tr>
    @endforeach
    <tr class="bg-sunken">
        <td class="px-6 py-3 text-sm font-semibold text-fg">{{ __('All suppliers') }}</td>
        @foreach (array_keys($headings) as $bucket)
            <td class="px-6 py-3 text-right text-sm font-semibold tabular-nums text-fg"><x-ui.amount :value="$table['totals'][$bucket]" /></td>
        @endforeach
        <td class="px-6 py-3 text-right text-sm font-semibold tabular-nums text-fg"><x-ui.amount :value="$table['totals']['total']" :currency="$currency" /></td>
    </tr>
</x-ui.table>
