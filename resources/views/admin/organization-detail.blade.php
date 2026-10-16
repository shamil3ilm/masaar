@extends('layouts.admin')

@section('title', $organization->name)

@section('content')
<div class="mb-6">
    <div class="flex items-center space-x-2 text-sm text-gray-500 dark:text-gray-400 mb-2">
        <a href="{{ route('admin.organizations') }}" class="hover:text-teal-700 dark:hover:text-teal-300">Organizations</a>
        <span>/</span>
        <span>{{ $organization->name }}</span>
    </div>
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $organization->name }}</h2>
</div>

@if (in_array($organization->status, ['pending_review', 'rejected'], true))
<div class="mb-6 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 p-6">
    <h3 class="text-lg font-semibold text-amber-900 dark:text-amber-100">
        {{ $organization->status === 'rejected' ? 'Not approved' : 'Awaiting review' }}
    </h3>
    <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
        Signed up {{ \Illuminate\Support\Carbon::parse($organization->created_at)->diffForHumans() }}.
        Check the company details below against its VAT and CR registration before approving: approval lets it obtain a ZATCA certificate and issue.
    </p>
    @if ($organization->review_note)
        <p class="mt-2 text-sm text-amber-800 dark:text-amber-200"><strong>Reason given:</strong> {{ $organization->review_note }}</p>
    @endif

    <div class="mt-4 grid grid-cols-1 gap-6 md:grid-cols-2">
        <form method="POST" action="{{ route('admin.organizations.approve', $organization->id) }}" class="space-y-3"
              data-confirm-action="Approve"
              data-confirm="Approve {{ $organization->name }} and assign the plan? It can then connect to ZATCA and issue.">
            @csrf
            <x-ui.field label="Plan">
                <x-ui.select name="tier" required>
                    @foreach (\App\Domains\Licensing\Enums\LicenseTier::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->getDisplayName() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field label="Plan ends (optional)">
                <x-ui.input type="date" name="expires_at" />
            </x-ui.field>
            <x-ui.button variant="primary" class="w-full">Approve and assign plan</x-ui.button>
        </form>

        @if ($organization->status === 'pending_review')
        <form method="POST" action="{{ route('admin.organizations.reject', $organization->id) }}" class="space-y-3"
              data-confirm-tone="danger" data-confirm-action="Turn down"
              data-confirm="Turn down {{ $organization->name }}? Its admins are emailed the reason.">
            @csrf
            <x-ui.field label="Reason, sent to the company">
                <x-ui.textarea name="reason" rows="4" maxlength="1000" required></x-ui.textarea>
            </x-ui.field>
            <x-ui.button variant="danger" class="w-full">Turn down</x-ui.button>
        </form>
        @endif
    </div>
</div>
@endif

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Invoices</div>
        <div class="text-3xl font-bold text-gray-900 dark:text-gray-100 mt-2">{{ number_format($stats['invoices']) }}</div>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Submissions</div>
        <div class="text-3xl font-bold text-gray-900 dark:text-gray-100 mt-2">{{ number_format($stats['submissions']) }}</div>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Cleared</div>
        <div class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2">{{ number_format($stats['cleared']) }}</div>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Rejected</div>
        <div class="text-3xl font-bold text-red-600 dark:text-red-400 mt-2">{{ number_format($stats['rejected']) }}</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Organization Details -->
    <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-lg shadow">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Organization Details</h3>
        </div>
        <div class="p-6">
            <dl class="grid grid-cols-2 gap-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">ID</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100 font-mono mt-1">{{ $organization->id }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="mt-1">
                        <x-ui.badge :state="$organization->status" />
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">VAT Number</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100 mt-1">{{ $organization->vat_number ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">CR Number</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100 mt-1">{{ $organization->cr_number ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">City</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100 mt-1">{{ $organization->city ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Country</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100 mt-1">{{ $organization->country ?? 'SA' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Created</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100 mt-1">{{ $organization->created_at ? \Carbon\Carbon::parse($organization->created_at)->format('M d, Y H:i') : '-' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Updated</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100 mt-1">{{ $organization->updated_at ? \Carbon\Carbon::parse($organization->updated_at)->format('M d, Y H:i') : '-' }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Certificate Status -->
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Certificate Status</h3>
        </div>
        <div class="p-6">
            @if($certificate)
                @php
                    $daysLeft = $certificate->valid_to ? now()->diffInDays($certificate->valid_to, false) : null;
                @endphp
                <div class="text-center mb-4">
                    @if($daysLeft !== null && $daysLeft > 0)
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full {{ $daysLeft <= 7 ? 'bg-red-100 dark:bg-red-900/40' : ($daysLeft <= 30 ? 'bg-yellow-100 dark:bg-yellow-900/40' : 'bg-green-100 dark:bg-green-900/40') }}">
                            <span class="text-2xl font-bold {{ $daysLeft <= 7 ? 'text-red-600 dark:text-red-400' : ($daysLeft <= 30 ? 'text-yellow-600 dark:text-yellow-400' : 'text-green-600 dark:text-green-400') }}">{{ $daysLeft }}</span>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">days until expiry</p>
                    @else
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-red-100 dark:bg-red-900/40">
                            <svg class="w-10 h-10 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </div>
                        <p class="text-sm text-red-600 dark:text-red-400 mt-2 font-medium">Certificate Expired</p>
                    @endif
                </div>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Serial</dt>
                        <dd class="text-gray-900 dark:text-gray-100 font-mono text-xs">{{ Str::limit($certificate->serial_number ?? '-', 16) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Valid From</dt>
                        <dd class="text-gray-900 dark:text-gray-100">{{ $certificate->valid_from ? \Carbon\Carbon::parse($certificate->valid_from)->format('M d, Y') : '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Valid To</dt>
                        <dd class="text-gray-900 dark:text-gray-100">{{ $certificate->valid_to ? \Carbon\Carbon::parse($certificate->valid_to)->format('M d, Y') : '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                        <dd>
                            <x-ui.badge :state="$certificate->status" />
                        </dd>
                    </div>
                </dl>
            @else
                <div class="text-center py-8">
                    <svg class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No active certificate</p>
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Members -->
<div class="bg-white dark:bg-gray-900 rounded-lg shadow mb-6">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Members</h3>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>User</x-ui.th>
            <x-ui.th>Role</x-ui.th>
            <x-ui.th>Membership</x-ui.th>
            <x-ui.th>Account</x-ui.th>
            <x-ui.th>Joined</x-ui.th>
        </x-slot:head>

        @forelse($members as $member)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4">
                <a href="{{ route('admin.users.show', $member->id) }}" class="font-medium text-teal-700 dark:text-teal-300 hover:text-teal-900 dark:hover:text-teal-200">{{ $member->name }}</a>
                <div class="text-sm text-gray-500 dark:text-gray-400 break-all">{{ $member->email }}</div>
            </td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $member->role }}</td>
            <td class="px-6 py-4"><x-ui.badge :state="$member->status" /></td>
            <td class="px-6 py-4"><x-ui.badge :state="$member->account_status" /></td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $member->joined_at ? \Carbon\Carbon::parse($member->joined_at)->format('M d, Y') : '-' }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="5">No members</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<!-- Recent Submissions -->
<div class="bg-white dark:bg-gray-900 rounded-lg shadow">
    <div class="p-6 border-b flex justify-between items-center">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Recent Submissions</h3>
        <a href="{{ route('admin.logs', ['org_id' => $organization->id]) }}" class="text-sm text-teal-700 dark:text-teal-300 hover:text-teal-900 dark:hover:text-teal-200">View All</a>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Invoice ID</x-ui.th>
            <x-ui.th>State</x-ui.th>
            <x-ui.th>Submission Type</x-ui.th>
            <x-ui.th>Created</x-ui.th>
        </x-slot:head>

        @forelse($recentSubmissions as $submission)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4 text-sm font-mono text-gray-900 dark:text-gray-100">{{ Str::limit($submission->invoice_id, 12) }}</td>
            <td class="px-6 py-4">
                <x-ui.badge :state="$submission->state" />
            </td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $submission->submission_type ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $submission->created_at ? \Carbon\Carbon::parse($submission->created_at)->format('M d, Y H:i') : '-' }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="4">No submissions found</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
