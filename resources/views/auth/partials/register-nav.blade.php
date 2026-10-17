{{--
    Back and Continue between sign-up's steps. Hidden until resources/js/ui.js
    runs, because without it every step shows at once and there is nothing
    to move between.

    @param bool $first  the first step, which has nothing to go back to
--}}
<div class="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
    @empty($first)
        <x-ui.button type="button" variant="ghost" data-step-back hidden>
            <svg class="size-4 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6" /></svg>
            {{ __('Back') }}
        </x-ui.button>
    @else
        <span></span>
    @endempty
    <x-ui.button type="button" data-step-next hidden>
        {{ __('Continue') }}
        <svg class="size-4 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" /></svg>
    </x-ui.button>
</div>
