{{--
    A customer's statement with the platform (Statements::statement): the
    opening balance, each line with the balance after it, and the closing
    balance. Shared by the console and the portal.

    statement   the statement array
--}}
<x-ui.table :primary="[0, 2, 5]">
    <x-slot:head>
        <x-ui.th>{{ __('Date') }}</x-ui.th>
        <x-ui.th>{{ __('Reference') }}</x-ui.th>
        <x-ui.th>{{ __('Description') }}</x-ui.th>
        <x-ui.th align="right">{{ __('Charged') }}</x-ui.th>
        <x-ui.th align="right">{{ __('Paid or credited') }}</x-ui.th>
        <x-ui.th align="right">{{ __('Balance') }}</x-ui.th>
    </x-slot:head>
    <tr class="bg-sunken">
        <td class="px-6 py-3 text-sm text-muted">{{ $statement['from'] }}</td>
        <td class="px-6 py-3 text-sm text-muted"></td>
        <td class="px-6 py-3 text-sm font-medium text-fg">{{ __('Opening balance') }}</td>
        <td class="px-6 py-3"></td>
        <td class="px-6 py-3"></td>
        <td class="px-6 py-3 text-right text-sm font-medium text-fg"><x-ui.amount :value="$statement['opening']" :currency="$statement['currency']" /></td>
    </tr>
    @foreach ($statement['lines'] as $line)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-3 text-sm text-muted">{{ $line['date'] }}</td>
            <td class="px-6 py-3 text-sm text-fg" dir="ltr">{{ $line['reference'] }}</td>
            <td class="px-6 py-3 text-sm text-muted">{{ $line['description'] }}</td>
            <td class="px-6 py-3 text-right text-sm tabular-nums text-fg">@if ($line['debit'] !== '')<x-ui.amount :value="$line['debit']" />@endif</td>
            <td class="px-6 py-3 text-right text-sm tabular-nums text-fg">@if ($line['credit'] !== '')<x-ui.amount :value="$line['credit']" />@endif</td>
            <td class="px-6 py-3 text-right text-sm tabular-nums text-fg"><x-ui.amount :value="$line['balance']" /></td>
        </tr>
    @endforeach
    <tr class="bg-sunken">
        <td class="px-6 py-3 text-sm text-muted">{{ $statement['to'] }}</td>
        <td class="px-6 py-3 text-sm text-muted"></td>
        <td class="px-6 py-3 text-sm font-semibold text-fg">{{ __('Closing balance') }}</td>
        <td class="px-6 py-3"></td>
        <td class="px-6 py-3"></td>
        <td class="px-6 py-3 text-right text-sm font-semibold text-fg"><x-ui.amount :value="$statement['closing']" :currency="$statement['currency']" /></td>
    </tr>
</x-ui.table>
