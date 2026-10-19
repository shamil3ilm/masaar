{{--
    Back and Continue between sign-up's steps. Hidden until resources/js/ui.js
    runs, because without it every step shows at once and there is nothing
    to move between.

    @param bool $first  the first step, which has nothing to go back to
--}}
<div class="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
    @empty($first)
        <x-ui.button type="button" variant="ghost" data-step-back hidden>
            <x-ui.icon name="chevron-start" class="size-4" />
            {{ __('Back') }}
        </x-ui.button>
    @else
        <span></span>
    @endempty
    <x-ui.button type="button" data-step-next hidden>
        {{ __('Continue') }}
        <x-ui.icon name="chevron-end" class="size-4" />
    </x-ui.button>
</div>
