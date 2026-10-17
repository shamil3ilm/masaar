@props(['href', 'active' => false, 'collapsible' => false])

{{--
    One link in a sidebar.

    Both layouts wrote each link's class string out in full, a dozen times
    each, with the active state worked out inline - and the admin console's
    and the portal's had drifted into two different designs. One shape now:
    the current page is marked by colour, by a bar on the start edge and by
    aria-current, so it does not depend on colour alone.

    @slot icon   a 20px outline icon.
    collapsible  the label hides when the admin console's sidebar collapses
                 to icons (Alpine's `labelled`), and the link keeps its name
                 through aria-label.
--}}
<a href="{{ $href }}" @if ($active) aria-current="page" @endif
   @if ($collapsible) aria-label="{{ trim(strip_tags((string) $slot)) }}" @endif
   {{ $attributes->class([
       'relative flex min-h-11 items-center gap-3 rounded-control px-3 text-sm font-medium transition-colors',
       'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
       'bg-accent-soft text-accent before:absolute before:inset-y-2 before:start-0 before:w-0.5 before:rounded-full before:bg-accent' => $active,
       'text-muted hover:bg-sunken hover:text-fg' => ! $active,
   ]) }}>
    @isset($icon)
        <span class="size-5 shrink-0" aria-hidden="true">{{ $icon }}</span>
    @endisset
    <span @if ($collapsible) x-show="labelled" @endif class="truncate">{{ $slot }}</span>
</a>
