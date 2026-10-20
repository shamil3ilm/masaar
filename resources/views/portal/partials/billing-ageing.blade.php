{{--
    What is owed by how long past due, in one currency (Statements::ageing).
    In the console a row per customer and the totals; for one customer, its
    one row.

    table      ['rows' => [...], 'totals' => [...]]
    headings   bucket => heading
    currency   the table's currency
    customers  whether to show a row per customer, linked to its billing page
--}}
<x-ui.table :primary="$customers ? [0, 6] : [5, 0]">
    <x-slot:head>
        @if ($customers)
            <x-ui.th>{{ __('Customer') }}</x-ui.th>
        @endif
        @foreach ($headings as $heading)
            <x-ui.th align="right">{{ $heading }}</x-ui.th>
        @endforeach
        <x-ui.th align="right">{{ __('Total (:currency)', ['currency' => $currency]) }}</x-ui.th>
    </x-slot:head>
    @if ($customers)
        @foreach ($table['rows'] as $row)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-3 text-sm font-medium text-fg"><a href="{{ route('admin.billing.show', $row['organization_id']) }}" class="text-accent hover:underline">{{ $row['name'] }}</a></td>
                @foreach (array_keys($headings) as $bucket)
                    <td class="px-6 py-3 text-right text-sm tabular-nums text-fg"><x-ui.amount :value="$row[$bucket]" /></td>
                @endforeach
                <td class="px-6 py-3 text-right text-sm font-semibold tabular-nums text-fg"><x-ui.amount :value="$row['total']" /></td>
            </tr>
        @endforeach
    @endif
    <tr class="bg-sunken">
        @if ($customers)
            <td class="px-6 py-3 text-sm font-semibold text-fg">{{ __('All customers') }}</td>
        @endif
        @foreach (array_keys($headings) as $bucket)
            <td class="px-6 py-3 text-right text-sm font-semibold tabular-nums text-fg"><x-ui.amount :value="$table['totals'][$bucket]" /></td>
        @endforeach
        <td class="px-6 py-3 text-right text-sm font-semibold tabular-nums text-fg"><x-ui.amount :value="$table['totals']['total']" :currency="$currency" /></td>
    </tr>
</x-ui.table>
