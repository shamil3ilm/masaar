{{--
    One population of submissions past an age without having reached ZATCA.

    Read-only, and the invoice number links nowhere: there is no admin invoice
    page, and a link to one that does not exist is worse than plain text.

    Expects: $rows (collection of rows from DeadlineReport), $empty (what to
    say when there are none).
--}}
<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Organization</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Age</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">State</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Retries</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last error</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($rows as $row)
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4">
                    <div class="font-medium text-gray-900">{{ $row['invoice_number'] }}</div>
                    <div class="text-xs text-gray-500">{{ Str::limit($row['id'], 8, '') }}</div>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ $row['organization'] ?? '-' }}</td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ number_format($row['age_hours'], 1) }}h</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs font-medium rounded-full {{ $row['state'] === 'failed' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800' }}">
                        {{ $row['state'] ?? 'unknown' }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ $row['retry_count'] }}</td>
                <td class="px-6 py-4 text-sm text-gray-500">
                    {{ $row['last_error'] === null ? '-' : Str::limit($row['last_error'], 80) }}
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="px-6 py-4 text-center text-gray-500">{{ $empty }}</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
