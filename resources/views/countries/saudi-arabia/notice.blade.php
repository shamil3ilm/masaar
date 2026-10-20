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
        {{ __('ZATCA :environment: invoices here are not legally filed with ZATCA.', ['environment' => $zatcaEnvironment]) }}
    </div>
@endif
