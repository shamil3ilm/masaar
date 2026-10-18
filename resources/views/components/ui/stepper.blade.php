@props(['steps', 'current' => 0, 'label' => null])

{{--
    Where someone is in a task of several steps, and what is left.

    An ordered list, so it is announced as "list, 4 items" and each step's
    number is its position. The current step carries aria-current="step";
    steps before it are marked done with a tick as well as a colour.

    steps    list of ['title' => ..., 'hint' => ...]
    current  the index of the step being worked on

    resources/js/ui.js moves the current step when a form drives it
    (data-steps on the form); the server renders the starting state, so the
    list is right before any script runs.
--}}
<nav aria-label="{{ $label ?? __('Progress') }}" {{ $attributes }}>
    <ol class="grid gap-3 sm:auto-cols-fr sm:grid-flow-col" data-stepper>
        @foreach ($steps as $index => $step)
            @php($state = $index < $current ? 'done' : ($index === $current ? 'current' : 'upcoming'))
            <li data-step-indicator="{{ $index }}" data-state="{{ $state }}" @if ($state === 'current') aria-current="step" @endif
                class="group flex items-start gap-3 rounded-tile border border-line bg-surface p-3 data-[state=current]:border-accent data-[state=current]:bg-accent-soft">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-line-strong text-sm font-semibold text-subtle tabular-nums group-data-[state=current]:border-accent group-data-[state=current]:text-accent group-data-[state=done]:border-action group-data-[state=done]:bg-action group-data-[state=done]:text-on-action">
                    <span class="group-data-[state=done]:hidden">{{ $index + 1 }}</span>
                    <svg class="hidden size-4 group-data-[state=done]:block" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-fg">{{ $step['title'] }}</span>
                    @if (! empty($step['hint']))
                        <span class="mt-0.5 block text-xs text-subtle">{{ $step['hint'] }}</span>
                    @endif
                    <span class="sr-only hidden group-data-[state=done]:block">{{ __('Done') }}</span>
                </span>
            </li>
        @endforeach
    </ol>
</nav>
