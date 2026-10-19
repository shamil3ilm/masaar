{{--
    A report's period branch by branch, beside the combined figures: each
    branch's documents, net amount and tax as its country's report counts
    them (TaxReports::totals). Nothing when the report shows one branch.

    @param $rows  list of [id, name, documents, base, vat, currency] (BranchBreakdown)
--}}
@if ($rows !== [])
    <div class="mb-6 overflow-hidden rounded-card border border-line bg-surface shadow-card">
        <div class="border-b border-line p-6">
            <h3 class="text-lg font-semibold text-fg">{{ __('By branch') }}</h3>
            <p class="text-sm text-muted">{{ __('The same period, branch by branch. Head office is what names no branch.') }}</p>
        </div>
        <x-ui.table :primary="[0, 3, 2]">
            <x-slot:head>
                <x-ui.th>{{ __('Branch') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Documents') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Net') }}</x-ui.th>
                <x-ui.th align="right">{{ __('VAT') }}</x-ui.th>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr>
                    <td class="px-6 py-4 text-sm font-medium text-fg">{{ $row['name'] }}</td>
                    <td class="px-6 py-4 text-end text-sm tabular-nums text-muted">{{ $row['documents'] }}</td>
                    <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$row['base']" :currency="$row['currency']" /></td>
                    <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$row['vat']" :currency="$row['currency']" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </div>
@endif
