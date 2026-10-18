{{--
    A branch as a ZATCA EGS unit, on the shared branch page (Devices::branchStatus).

    $status      the branch's onboarding status
    $ready       whether ZATCA accepts invoices from it now
    $serial      the device serial its certificate was issued against
    $compliance  whether it holds a compliance CSID
    $production  whether it holds a production CSID
    $expires     when its certificate ends, or null
    $days        days left on it, or null
--}}
<x-ui.section :title="__('ZATCA device')" :description="__('Each branch is its own EGS unit, onboarded with ZATCA on its own.')">
    <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-subtle">{{ __('Onboarding') }}</dt>
            <dd class="mt-1"><x-ui.badge :state="$status" /></dd>
        </div>
        <div>
            <dt class="text-subtle">{{ __('Device serial') }}</dt>
            <dd class="mt-1 font-mono text-fg" dir="ltr">{{ $serial }}</dd>
        </div>
        <div>
            <dt class="text-subtle">{{ __('Compliance CSID') }}</dt>
            <dd class="mt-1"><x-ui.badge :tone="$compliance ? 'success' : 'neutral'" :label="$compliance ? __('Held') : __('Not yet')" /></dd>
        </div>
        <div>
            <dt class="text-subtle">{{ __('Production CSID') }}</dt>
            <dd class="mt-1"><x-ui.badge :tone="$production ? 'success' : 'neutral'" :label="$production ? __('Held') : __('Not yet')" /></dd>
        </div>
        <div class="sm:col-span-2">
            <dt class="text-subtle">{{ __('Certificate') }}</dt>
            <dd class="mt-1 text-fg">
                @if ($expires)
                    <span dir="ltr">{{ $expires->toDateString() }}</span>
                    @if ($days !== null)
                        <span class="text-muted">· {{ $days >= 0 ? __(':days days left', ['days' => $days]) : __('Expired') }}</span>
                    @endif
                @else
                    <span class="text-muted">{{ $production ? __('No end date recorded') : __('Signs with head office\'s certificate until it has its own.') }}</span>
                @endif
            </dd>
        </div>
    </dl>

    @unless ($ready)
        <x-ui.alert tone="warning" class="mt-4" :title="__('ZATCA would refuse invoices from this branch now.')">
            {{ __('Onboard it through the branch onboarding API with an OTP from the Fatoora portal. Until then, issue from head office: choose All branches in the switcher.') }}
        </x-ui.alert>
    @endunless
</x-ui.section>
