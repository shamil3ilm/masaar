@props(['resolved', 'action'])

{{--
    One customization on a settings page, the same on every page that has
    them - the platform's defaults in the console, an organization's
    settings, a person's own preferences.

    It says what is in effect and where that comes from (the shipped default,
    a level above, or set here), offers the control for this level's own
    value, and - when this level has one - "Reset to inherited", which
    deletes it so the level above decides again. A value a level above has
    locked is shown, not offered.

    resolved  a Customization\Resolved, resolved at the page's level
              (Customizations::describe)
    action    where the form posts: the page's update route for the key
--}}
@php
    $definition = $resolved->definition;
    $current = $resolved->own ?? $resolved->value;
    $lockedAbove = $resolved->lockedBy !== null && $resolved->lockedBy !== $resolved->at;
    $deepest = $definition->deepest() === $resolved->at;
    $tone = $resolved->inherited() ? 'neutral' : 'info';
@endphp

<form method="POST" action="{{ $action }}" class="flex flex-col gap-4 border-t border-line p-5 first:border-t-0 md:flex-row md:items-end md:justify-between">
    @csrf
    <div class="min-w-0 md:max-w-md">
        <p class="font-medium text-fg">{{ $definition->label }}</p>
        <p class="mt-0.5 text-sm text-subtle">{{ $definition->help }}</p>
        <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted">
            <span>{{ __('In effect: :value', ['value' => $definition->display($resolved->value) ?: __('Default')]) }}</span>
            <x-ui.badge :tone="$tone" :label="$resolved->sourceLabel()" />
        </p>
    </div>

    @if ($lockedAbove)
        <p class="text-sm text-subtle md:max-w-xs">{{ __('A level above has locked this, so it cannot be changed here.') }}</p>
    @else
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
            <x-ui.field :label="__('Value here')" :error="$definition->key" class="sm:w-56">
                @if ($definition->type === 'bool')
                    <x-ui.select name="value">
                        <option value="1" @selected($current === true)>{{ __('On') }}</option>
                        <option value="0" @selected($current === false)>{{ __('Off') }}</option>
                    </x-ui.select>
                @elseif ($definition->type === 'choice')
                    <x-ui.select name="value">
                        @foreach ($definition->options as $value => $label)
                            <option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                @else
                    <x-ui.input type="number" name="value" inputmode="numeric" dir="ltr" value="{{ $current }}"
                                min="{{ $definition->min }}" max="{{ $definition->max }}" step="{{ $definition->step }}" />
                @endif
            </x-ui.field>

            @if ($definition->lockable && ! $deepest)
                <x-ui.checkbox name="locked" value="1" :checked="$resolved->lockedBy === $resolved->at && $resolved->own !== null">
                    {{ __('Lock for the levels below') }}
                </x-ui.checkbox>
            @endif

            <div class="flex flex-wrap gap-2">
                <x-ui.button variant="secondary" size="sm">{{ __('Save') }}</x-ui.button>
                @if ($resolved->own !== null)
                    <x-ui.button variant="ghost" size="sm" name="reset" value="1" formnovalidate
                                 data-confirm-action="{{ __('Reset') }}"
                                 data-confirm="{{ __('Reset :setting to the inherited value?', ['setting' => $definition->label]) }}"
                                 data-confirm-detail="{{ __('The value set here is dropped and the one above applies again. You can set it again at any time.') }}">{{ __('Reset to inherited') }}</x-ui.button>
                @endif
            </div>
        </div>
    @endif
</form>
