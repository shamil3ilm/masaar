{{--
    Which ZATCA environment the organization in the portal files with
    (FatooraEnvironment), or in the console the deployment's default,
    whenever it is not production.

    A live server left on simulation or the sandbox files nothing, and every
    submission still answers as accepted - nothing else on any page would
    show it. Simulation is allowed in production (a deployment rehearses
    there before going live), so it is made impossible to miss instead.
--}}
@php($zatcaEnvironment = \App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment::of($navOrganization ?? null))
@if ($zatcaEnvironment !== 'production')
    <div role="status" class="bg-warning px-4 py-2 text-center text-sm font-medium text-ground">
        {{ __('ZATCA :environment: invoices here are not legally filed with ZATCA.', ['environment' => \App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment::label($zatcaEnvironment)]) }}
    </div>
@endif
{{-- A device ZATCA refused the credentials of files nothing until they are
     renewed (FilingPause): said on every page of the portal. --}}
@if (isset($navOrganization) && $navOrganization)
    @php($pausedTills = app(\App\Domains\Organization\Services\Tills::class)->paused((string) $navOrganization->id))
    @if ($pausedTills->isNotEmpty())
        <div role="alert" class="bg-danger px-4 py-2 text-center text-sm font-medium text-ground" data-filing-paused>
            {{ __('ZATCA refused the certificate of :devices, so filing from it is paused. Invoices wait and are filed once it is renewed.', ['devices' => $pausedTills->map(fn ($unit) => app(\App\Domains\Organization\Services\Tills::class)->label($unit))->implode(', ')]) }}
            <a href="{{ route('portal.zatca.certificates') }}" class="underline">{{ __('Renew the certificate') }}</a>
        </div>
    @endif
@endif
