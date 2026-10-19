@extends('layouts.admin')

@section('title', $organization->name)

@section('content')
<div class="mb-6">
    <div class="flex items-center space-x-2 text-sm text-muted mb-2">
        <a href="{{ route('admin.organizations') }}" class="hover:text-accent-strong">Organizations</a>
        <span>/</span>
        <span>{{ $organization->name }}</span>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ $organization->name }}</h1>
        <x-ui.button variant="secondary" :href="route('admin.billing.show', $organization->id)">Plan and billing</x-ui.button>
    </div>
</div>

@if (in_array($organization->status, ['pending_review', 'rejected'], true))
<div class="mb-6 rounded-lg border border-warning-line bg-warning-soft p-6">
    <h3 class="text-lg font-semibold text-warning">
        {{ $organization->status === 'rejected' ? 'Not approved' : 'Awaiting review' }}
    </h3>
    <p class="mt-1 text-sm text-warning">
        Signed up {{ \Illuminate\Support\Carbon::parse($organization->created_at)->diffForHumans() }}.
        Check the company details below against its VAT and CR registration before approving: approval lets it obtain a ZATCA certificate and issue.
    </p>
    @if ($organization->review_note)
        <p class="mt-2 text-sm text-warning"><strong>Reason given:</strong> {{ $organization->review_note }}</p>
    @endif

    <div class="mt-4 grid grid-cols-1 gap-6 md:grid-cols-2">
        @admincan('organizations.review')
        <form method="POST" action="{{ route('admin.organizations.approve', $organization->id) }}" class="space-y-3"
              data-confirm-action="Approve"
              data-confirm="Approve {{ $organization->name }} and assign the plan?"
              data-confirm-detail="It can then connect to its tax authority and issue. You can suspend it later.">
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
            @include('admin.partials.approval-reason', ['action' => 'organizations.approve'])
            <x-ui.button variant="primary" class="w-full">Approve and assign plan</x-ui.button>
        </form>
        @endadmincan

        @if ($organization->status === 'pending_review')
        @admincan('organizations.review')
        <form method="POST" action="{{ route('admin.organizations.reject', $organization->id) }}" class="space-y-3"
              data-confirm-tone="danger" data-confirm-action="Turn down"
              data-confirm="Turn down {{ $organization->name }}?"
              data-confirm-detail="Its admins are emailed the reason at once, and the email cannot be called back.">
            @csrf
            <x-ui.field label="Reason, sent to the company">
                <x-ui.textarea name="reason" rows="4" maxlength="1000" required></x-ui.textarea>
            </x-ui.field>
            <x-ui.button variant="danger" class="w-full">Turn down</x-ui.button>
        </form>
        @endadmincan
        @endif
    </div>
</div>
@endif

{{-- Suspension: nothing new is issued; its people keep their data and
     exports, and what it issued keeps being filed. --}}
@if ($organization->status === 'suspended')
    <x-ui.alert tone="warning" :title="__('Suspended')" class="mb-6">
        <p>{{ __('Since :when. Reason: :reason', ['when' => \Illuminate\Support\Carbon::parse($organization->suspended_at)->diffForHumans(), 'reason' => $organization->suspension_reason]) }}</p>
        @admincan('organizations.review')
            <form method="POST" action="{{ route('admin.organizations.reactivate', $organization->id) }}" class="mt-3 max-w-xl space-y-3"
                  data-confirm-action="{{ __('Reactivate') }}" data-confirm="{{ __('Reactivate :name?', ['name' => $organization->name]) }}"
                  data-confirm-detail="{{ __('It can issue again straight away. You can suspend it again.') }}">
                @csrf
                @include('admin.partials.approval-reason', ['action' => 'organizations.reactivate'])
                <x-ui.button variant="primary">{{ __('Reactivate organization') }}</x-ui.button>
            </form>
        @endadmincan
    </x-ui.alert>
@elseif ($organization->status === 'active')
    @admincan('organizations.review')
        <x-ui.section :title="__('Suspend this organization')" :description="__('It issues nothing new. Its people still sign in, read and export everything, and invoices it already issued keep being filed.')" closed class="mb-6">
            <form method="POST" action="{{ route('admin.organizations.suspend', $organization->id) }}" class="max-w-xl space-y-3"
                  data-confirm-tone="danger" data-confirm-action="{{ __('Suspend') }}" data-confirm="{{ __('Suspend :name?', ['name' => $organization->name]) }}"
                  data-confirm-detail="{{ __('It stops issuing at once, for everyone in it, until it is reactivated.') }}"
                  data-confirm-type="{{ $organization->name }}">
                @csrf
                <x-ui.field :label="__('Reason, shown to the organization')" error="reason">
                    <x-ui.textarea name="reason" rows="3" maxlength="500" required></x-ui.textarea>
                </x-ui.field>
                <x-ui.button variant="danger">{{ __('Suspend organization') }}</x-ui.button>
            </form>
        </x-ui.section>
    @endadmincan
@endif

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <div class="text-sm font-medium text-muted">Total Invoices</div>
        <div class="text-3xl font-semibold text-fg mt-2">{{ number_format($stats['invoices']) }}</div>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <div class="text-sm font-medium text-muted">Total Submissions</div>
        <div class="text-3xl font-semibold text-fg mt-2">{{ number_format($stats['submissions']) }}</div>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <div class="text-sm font-medium text-muted">Cleared</div>
        <div class="text-3xl font-semibold text-success mt-2">{{ number_format($stats['cleared']) }}</div>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <div class="text-sm font-medium text-muted">Rejected</div>
        <div class="text-3xl font-semibold text-danger mt-2">{{ number_format($stats['rejected']) }}</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Organization Details -->
    <x-ui.section title="Organization Details" collapse class="lg:col-span-2">
        <div>
            <dl class="grid grid-cols-2 gap-4">
                <div>
                    <dt class="text-sm font-medium text-muted">ID</dt>
                    <dd class="text-sm text-fg font-mono mt-1">{{ $organization->id }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-muted">Status</dt>
                    <dd class="mt-1">
                        <x-ui.badge :state="$organization->status" />
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-muted">VAT Number</dt>
                    <dd class="text-sm text-fg mt-1">{{ $organization->vat_number ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-muted">CR Number</dt>
                    <dd class="text-sm text-fg mt-1">{{ $organization->cr_number ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-muted">City</dt>
                    <dd class="text-sm text-fg mt-1">{{ $organization->city ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-muted">Country</dt>
                    <dd class="text-sm text-fg mt-1">{{ $organization->country ?? 'SA' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-muted">Created</dt>
                    <dd class="text-sm text-fg mt-1">{{ $organization->created_at ? \Carbon\Carbon::parse($organization->created_at)->format('M d, Y H:i') : '-' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-muted">Updated</dt>
                    <dd class="text-sm text-fg mt-1">{{ $organization->updated_at ? \Carbon\Carbon::parse($organization->updated_at)->format('M d, Y H:i') : '-' }}</dd>
                </div>
            </dl>
        </div>
    </x-ui.section>

    <!-- Certificate Status -->
    <div class="bg-surface rounded-card border border-line shadow-card">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold text-fg">Certificate Status</h3>
        </div>
        <div class="p-6">
            @if($certificate)
                @php
                    $daysLeft = $certificate->valid_to ? now()->diffInDays($certificate->valid_to, false) : null;
                @endphp
                <div class="text-center mb-4">
                    @if($daysLeft !== null && $daysLeft > 0)
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full {{ $daysLeft <= 7 ? 'bg-danger-soft' : ($daysLeft <= 30 ? 'bg-warning-soft' : 'bg-success-soft') }}">
                            <span class="text-2xl font-semibold {{ $daysLeft <= 7 ? 'text-danger' : ($daysLeft <= 30 ? 'text-warning' : 'text-success') }}">{{ $daysLeft }}</span>
                        </div>
                        <p class="text-sm text-muted mt-2">days until expiry</p>
                    @else
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-danger-soft">
                            <svg class="w-10 h-10 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </div>
                        <p class="text-sm text-danger mt-2 font-medium">Certificate Expired</p>
                    @endif
                </div>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted">Serial</dt>
                        <dd class="text-fg font-mono text-xs">{{ Str::limit($certificate->serial_number ?? '-', 16) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted">Valid From</dt>
                        <dd class="text-fg">{{ $certificate->valid_from ? \Carbon\Carbon::parse($certificate->valid_from)->format('M d, Y') : '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted">Valid To</dt>
                        <dd class="text-fg">{{ $certificate->valid_to ? \Carbon\Carbon::parse($certificate->valid_to)->format('M d, Y') : '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted">Status</dt>
                        <dd>
                            <x-ui.badge :state="$certificate->status" />
                        </dd>
                    </div>
                </dl>
            @else
                <div class="text-center py-8">
                    <svg class="mx-auto h-12 w-12 text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <p class="mt-2 text-sm text-muted">No active certificate</p>
                </div>
            @endif
        </div>
    </div>
</div>

@include('admin.partials.features')

@if ($arabic)
    @include('admin.partials.arabic-text')
@endif

<!-- Members -->
<x-ui.section title="Members" collapse flush class="mb-6">
    <x-ui.table :primary="[0, 1, 2]">
        <x-slot:head>
            <x-ui.th>User</x-ui.th>
            <x-ui.th>Role</x-ui.th>
            <x-ui.th>Membership</x-ui.th>
            <x-ui.th>Account</x-ui.th>
            <x-ui.th>Joined</x-ui.th>
        </x-slot:head>

        @forelse($members as $member)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4">
                <a href="{{ route('admin.users.show', $member->id) }}" class="font-medium text-accent hover:text-accent-strong">{{ $member->name }}</a>
                <div class="text-sm text-muted break-all">{{ $member->email }}</div>
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ $member->role }}</td>
            <td class="px-6 py-4"><x-ui.badge :state="$member->status" /></td>
            <td class="px-6 py-4"><x-ui.badge :state="$member->account_status" /></td>
            <td class="px-6 py-4 text-sm text-muted">{{ $member->joined_at ? \Carbon\Carbon::parse($member->joined_at)->format('M d, Y') : '-' }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="5">No members</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.section>

<!-- Recent Submissions -->
<x-ui.section title="Recent Submissions" collapse flush>
    <p class="px-5 pt-3 text-sm"><a href="{{ route('admin.logs', ['org_id' => $organization->id]) }}" class="inline-flex min-h-11 items-center text-accent hover:text-accent-strong">View All</a></p>
    <x-ui.table :primary="[0, 1, 3]">
        <x-slot:head>
            <x-ui.th>Invoice ID</x-ui.th>
            <x-ui.th>State</x-ui.th>
            <x-ui.th>Submission Type</x-ui.th>
            <x-ui.th>Created</x-ui.th>
        </x-slot:head>

        @forelse($recentSubmissions as $submission)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm font-mono text-fg">{{ Str::limit($submission->invoice_id, 12) }}</td>
            <td class="px-6 py-4">
                <x-ui.badge :state="$submission->state" />
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ $submission->submission_type ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ $submission->created_at ? \Carbon\Carbon::parse($submission->created_at)->format('M d, Y H:i') : '-' }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="4">No submissions found</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.section>
@endsection
