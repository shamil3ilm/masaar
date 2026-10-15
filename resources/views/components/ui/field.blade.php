@props(['label'])

{{--
    A control with a name a screen reader can read.

    The filter controls on admin/logs and admin/queue had a placeholder and
    nothing else. A placeholder is not a name: it vanishes the moment someone
    types, so it cannot be re-read, and the select on admin/queue had no
    placeholder either - it was announced as "combo box" with no indication
    of what it filtered.

    The control is nested inside the label rather than paired by id, which is
    what portal/invoices/partials/line-row already does and for the same
    reason: an id has to be unique on the page, and a component used twice in
    one form would duplicate it and associate the label with whichever field
    the browser reached first. Nesting has nothing to collide.

    The label is visible, not sr-only. A filter whose name is only announced
    to a screen reader is a filter sighted users have to guess at too - which
    is what a bare placeholder already was.
--}}
<label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
    {{ $label }}
    {{ $slot }}
</label>
