{{--
    Every feature with what Access answers for this organization: allowed or
    not, why, and the level that decided (docs/architecture/ACCESS.md). The
    role level is left out: there is no person here, only the company.

    Every admin role reads it. Only features.manage changes a switch, and only
    on a feature whose every path honours it; a reason is required, and the
    change is audited with the state before and after.
--}}
<div id="features" class="bg-surface rounded-card border border-line shadow-card mb-6">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-fg">Features</h3>
        <p class="mt-1 text-sm text-muted">
            What this company can use now, and why not. Checked in order - platform, country, plan, organization - and the first that says no decides.
        </p>
    </div>
    <x-ui.table :primary="[0, 1]">
        <x-slot:head>
            <x-ui.th>Feature</x-ui.th>
            <x-ui.th>State</x-ui.th>
            <x-ui.th>Decided by</x-ui.th>
            <x-ui.th>Reason</x-ui.th>
            <x-ui.th>Governed by</x-ui.th>
            <x-ui.th>Switch for this company</x-ui.th>
        </x-slot:head>

        @foreach ($features as $row)
            @php
                $feature = $row['feature'];
                $decision = $row['decision'];
                $override = $row['override'];
            @endphp
            <tr class="hover:bg-sunken align-top">
                <td class="px-6 py-4 text-sm font-medium text-fg">{{ $feature->label() }}</td>
                <td class="px-6 py-4">
                    <x-ui.badge :tone="$decision->allowed ? 'success' : 'danger'" :label="$decision->allowed ? 'On' : 'Off'" />
                </td>
                <td class="px-6 py-4 text-sm text-muted">{{ $decision->source?->label() ?? 'Every level' }}</td>
                <td class="px-6 py-4 text-sm text-fg">
                    {{ $decision->reason }}
                    <div class="text-xs text-subtle font-mono">{{ $decision->code }}</div>
                </td>
                <td class="px-6 py-4 text-xs text-muted">{{ implode(' · ', $row['governed']) }}</td>
                <td class="px-6 py-4 text-sm text-muted">
                    @if ($override)
                        <div class="mb-2 text-fg">
                            {{ ($override['state'] === 'off' ? 'Switched off' : 'Granted beyond the plan').' by '.($override['by'] ?? 'unknown').($override['at'] ? ' at '.$override['at'] : '').':' }}
                            {{ $override['reason'] }}
                        </div>
                    @endif

                    @if ($feature->overridable())
                        @admincan('features.manage')
                        <form method="POST" action="{{ route('admin.organizations.features', $organization->id) }}" class="space-y-2"
                              data-confirm-action="Change"
                              data-confirm="Change {{ $feature->label() }} for {{ $organization->name }}? It takes effect on the next request and is recorded with your reason.">
                            @csrf
                            <input type="hidden" name="feature" value="{{ $feature->value }}">
                            <x-ui.field label="Set to">
                                <x-ui.select name="state" required>
                                    <option value="default" @selected($override === null)>Default</option>
                                    @if ($feature->canSwitchOff())
                                        <option value="off" @selected(($override['state'] ?? null) === 'off')>Off for this company</option>
                                    @endif
                                    @if ($feature->canGrantBeyondPlan())
                                        <option value="on" @selected(($override['state'] ?? null) === 'on')>On beyond the plan</option>
                                    @endif
                                </x-ui.select>
                            </x-ui.field>
                            <x-ui.field label="Reason">
                                <x-ui.input name="reason" maxlength="500" required />
                            </x-ui.field>
                            <x-ui.button variant="secondary">Save</x-ui.button>
                        </form>
                        @endadmincan
                    @else
                        <span>Not switchable per company</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>

    {{-- Below the company: one role in it, or one person. Off only, and
         only for what every person-path asks Access about (issuing). The
         role level and then the person decide after everything above. --}}
    <div class="border-t border-line p-6">
        <h4 class="font-semibold text-fg">{{ __('For one role or one person') }}</h4>
        <p class="mt-1 text-sm text-muted">{{ __('Switch issuing off for everyone in this company with one role, or for one person. Checked after the company: platform, country, plan, organization, role, person.') }}</p>

        @if ($peopleSwitches !== [])
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($peopleSwitches as $switch)
                    <li class="text-fg">
                        <span class="font-medium">{{ $switch['feature']->label() }}</span>
                        · <span dir="ltr">{{ $switch['subject'] }}</span>
                        · {{ $switch['override']['reason'] }} <span class="text-subtle">({{ $switch['override']['by'] ?? '' }})</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @admincan('features.manage')
            <form method="POST" action="{{ route('admin.organizations.features', $organization->id) }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @csrf
                <input type="hidden" name="feature" value="{{ \App\Domains\Platform\Access\Feature::IssueInvoices->value }}">
                <x-ui.field :label="__('Who')" error="subject">
                    <x-ui.select name="subject" required>
                        @foreach (\App\Domains\Organization\Enums\MemberRole::cases() as $role)
                            <option value="role:{{ $role->value }}">{{ __('Everyone with the role: :role', ['role' => $role->label()]) }}</option>
                        @endforeach
                        @foreach ($members as $member)
                            <option value="user:{{ $member->id }}">{{ $member->email }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('Issuing')">
                    <x-ui.select name="state" required>
                        <option value="off">{{ __('Off') }}</option>
                        <option value="default">{{ __('Default') }}</option>
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('Reason')">
                    <x-ui.input name="reason" maxlength="500" required />
                </x-ui.field>
                <div class="flex items-end">
                    <x-ui.button variant="secondary">{{ __('Save') }}</x-ui.button>
                </div>
            </form>
        @endadmincan
    </div>
</div>
