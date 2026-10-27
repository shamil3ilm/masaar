@extends('layouts.portal')

@section('title', $branch ? $branch->name : __('New branch'))

@section('content')
<x-ui.page-header :title="$branch ? $branch->name : __('New branch')"
                  :description="$branch ? __('Till :number. Its address is printed on the invoices it issues.', ['number' => $branch->till_number ?? '—']) : __('A branch is a till of its own, numbered in the order branches are added. Its address is printed on the invoices it issues.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Branches'), 'href' => route('portal.branches.index')], ['label' => $branch ? $branch->name : __('New branch')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<div class="max-w-3xl space-y-6">
    <form method="POST" action="{{ $branch ? route('portal.branches.update', $branch->id) : route('portal.branches.store') }}" class="space-y-6">
        @csrf

        <x-ui.section :title="__('Name and address')">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Name')" error="name">
                    <x-ui.input name="name" required maxlength="255" value="{{ old('name', $branch?->name) }}" />
                </x-ui.field>
                <x-ui.field :label="__('Name in Arabic')" error="name_ar" optional>
                    <x-ui.input name="name_ar" maxlength="255" dir="rtl" lang="ar" value="{{ old('name_ar', $branch?->name_ar) }}" />
                </x-ui.field>
                <x-ui.field :label="__('Street')" error="street" class="sm:col-span-2">
                    <x-ui.input name="street" maxlength="255" value="{{ old('street', $branch?->street) }}" />
                </x-ui.field>
                <x-ui.field :label="__('Building number')" error="building_number">
                    <x-ui.input name="building_number" maxlength="4" inputmode="numeric" value="{{ old('building_number', $branch?->building_number) }}" />
                </x-ui.field>
                <x-ui.field :label="__('Additional number')" error="additional_number" optional>
                    <x-ui.input name="additional_number" maxlength="4" inputmode="numeric" value="{{ old('additional_number', $branch?->additional_number) }}" />
                </x-ui.field>
                @if ($registration ?? false)
                    {{-- Its own CR, when it has one: what its invoices then carry as the seller's ID (SellerParty). --}}
                    <x-ui.field :label="__('Branch commercial registration')" error="cr_number" optional :hint="__('Only if this branch has its own CR. Its invoices then carry it instead of the company registration.')">
                        <x-ui.input name="cr_number" maxlength="50" value="{{ old('cr_number', $branch?->cr_number) }}" />
                    </x-ui.field>
                @endif
                <x-ui.field :label="__('District')" error="district">
                    <x-ui.input name="district" maxlength="100" value="{{ old('district', $branch?->district) }}" />
                </x-ui.field>
                <x-ui.field :label="__('City')" error="city">
                    <x-ui.input name="city" maxlength="100" value="{{ old('city', $branch?->city) }}" />
                </x-ui.field>
                {{-- Where the country's return reports sales by the region they are made from (SellerRules::branchRegions). --}}
                @if (($regions ?? []) !== [])
                    <x-ui.field :label="__('Emirate')" error="emirate">
                        <x-ui.select name="emirate" required>
                            <option value="">{{ __('Choose the emirate') }}</option>
                            @foreach ($regions as $code => $name)
                                <option value="{{ $code }}" @selected(old('emirate', $branch?->emirate) === $code)>{{ __($name) }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif
                <x-ui.field :label="__('Postal code')" error="postal_code">
                    <x-ui.input name="postal_code" maxlength="5" inputmode="numeric" value="{{ old('postal_code', $branch?->postal_code) }}" />
                </x-ui.field>
            </div>
        </x-ui.section>

        @if ($branch)
            <x-ui.section collapse :title="__('Printer and paper')" :description="__('What this branch prints on, over the organization\'s paper. A person\'s own choice still comes first.')">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-ui.field :label="__('Paper')">
                        <x-ui.select name="paper">
                            <option value="" @selected($paper === null)>{{ __('As the organization') }}</option>
                            @foreach ($papers as $size)
                                <option value="{{ $size->value }}" @selected($paper === $size)>{{ $size->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field :label="__('Receipt printer dpi')" error="dpi" optional>
                        <x-ui.input type="number" name="dpi" min="100" max="600" placeholder="203" value="{{ old('dpi', $printer['dpi']) }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('Dots across the head')" error="dots" optional>
                        <x-ui.input type="number" name="dots" min="64" max="1280" step="8" placeholder="576" value="{{ old('dots', $printer['dots']) }}" />
                    </x-ui.field>
                </div>
            </x-ui.section>
        @endif

        <div class="flex flex-wrap gap-2">
            <x-ui.button>{{ $branch ? __('Save') : __('Add branch') }}</x-ui.button>
            <x-ui.button variant="ghost" :href="route('portal.branches.index')">{{ __('Cancel') }}</x-ui.button>
        </div>
    </form>

    @if ($branch)
        @if ($device)
            @include($device->view, $device->data)
        @endif

        <x-ui.section collapse :title="__('Numbering')" :description="__('A branch can number its invoices from a series of its own, or share the organization\'s.')">
            <x-ui.button variant="secondary" :href="route('portal.numbering.edit', ['branch' => $branch->id])">{{ __('Open this branch\'s numbering') }}</x-ui.button>
        </x-ui.section>

        <form method="POST" action="{{ route('portal.branches.people', $branch->id) }}">
            @csrf
            <x-ui.section :title="__('Who works here')" :description="__('Someone assigned to one or more branches sees and issues at those alone. Administrators work at every branch.')" flush>
                <x-ui.table :primary="[0, 2]">
                    <x-slot:head>
                        <x-ui.th>{{ __('Person') }}</x-ui.th>
                        <x-ui.th>{{ __('Role') }}</x-ui.th>
                        <x-ui.th>{{ __('At this branch') }}</x-ui.th>
                    </x-slot:head>
                    @forelse ($members as $member)
                        <tr>
                            <td class="px-6 py-4 text-sm">
                                <div class="font-medium text-fg">{{ $member->name ?: $member->email }}</div>
                                <div class="text-xs text-muted break-all">{{ $member->email }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm text-muted">{{ \App\Domains\Organization\Enums\MemberRole::tryFrom((string) $member->role)?->label() ?? __('Member') }}</td>
                            <td class="px-6 py-4 text-sm">
                                {{-- An owner or admin works at every branch, as the Team page has them. --}}
                                @if (\App\Domains\Organization\Enums\MemberRole::administers($member->role))
                                    <span class="text-muted">{{ __('Every branch') }}</span>
                                @else
                                    <x-ui.select name="people[{{ $member->id }}]" aria-label="{{ __('What :name does at this branch', ['name' => $member->name ?: $member->email]) }}">
                                            <option value="">{{ __('Not assigned') }}</option>
                                            <option value="member" @selected(($assignments[$member->id] ?? null) === 'member')>{{ __('Issues invoices') }}</option>
                                            <option value="viewer" @selected(($assignments[$member->id] ?? null) === 'viewer')>{{ __('Reads only') }}</option>
                                    </x-ui.select>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty :columns="3">{{ __('No one else is in the organization yet.') }}</x-ui.empty>
                    @endforelse
                </x-ui.table>
                <div class="border-t border-line px-5 py-4">
                    <x-ui.button variant="secondary">{{ __('Save who works here') }}</x-ui.button>
                </div>
            </x-ui.section>
        </form>
    @endif
</div>
@endsection
