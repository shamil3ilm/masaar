{{--
    One population of submissions past an age without having reached ZATCA.

    Read-only, and the invoice number links nowhere: there is no admin invoice
    page, and a link to one that does not exist is worse than plain text.

    Expects: $rows (collection of rows from DeadlineReport), $empty (what to
    say when there are none).
--}}
<div class="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Invoice</x-ui.th>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>Age</x-ui.th>
            <x-ui.th>State</x-ui.th>
            <x-ui.th>Retries</x-ui.th>
            <x-ui.th>Last error</x-ui.th>
        </x-slot:head>

        @forelse($rows as $row)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4">
                <div class="font-medium text-gray-900 dark:text-gray-100">{{ $row['invoice_number'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ Str::limit($row['id'], 8, '') }}</div>
            </td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $row['organization'] ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ number_format($row['age_hours'], 1) }}h</td>
            <td class="px-6 py-4">
                <x-ui.badge :state="$row['state']" />
            </td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $row['retry_count'] }}</td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                {{ $row['last_error'] === null ? '-' : Str::limit($row['last_error'], 80) }}
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">{{ $empty }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
