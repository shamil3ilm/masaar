@props(['accept' => null, 'multiple' => false, 'maxKb' => null, 'maxFiles' => null])

{{--
    A file to send: a drop zone over the browser's own file input
    (resources/js/file-field.js).

    The native input stays the value and is all that shows without script.
    With it, a zone takes a file dropped on it or chosen by click, Enter or
    Space (the input keeps focus, so the keyboard and a screen reader reach
    it as a file input), lists what was chosen with a preview of an image, and
    refuses a wrong type or too large a file in our words before anything is
    sent. A form carrying a chosen file shows its upload's progress.

    accept     what the input accepts, as the input takes it; the zone names
               the types it reads
    maxKb      the largest file the server takes, in kilobytes - the same
               constant its rule reads
    maxFiles   with multiple, how many at most
--}}
@php
    $labels = [
        'image/png' => 'PNG', '.png' => 'PNG', 'image/jpeg' => 'JPEG', '.jpg' => 'JPEG', '.jpeg' => 'JPEG',
        'image/webp' => 'WebP', '.webp' => 'WebP', 'image/svg+xml' => 'SVG', '.svg' => 'SVG',
        '.pdf' => 'PDF', 'application/pdf' => 'PDF', '.csv' => 'CSV', 'text/csv' => 'CSV',
        '.xml' => 'XML', '.txt' => __('text'), 'text/plain' => __('text'),
    ];
    $types = collect(explode(',', (string) $accept))->map(fn ($type) => $labels[strtolower(trim($type))] ?? null)->filter()->unique()->values();
    $size = $maxKb === null ? null : ($maxKb >= 1024 ? __(':count MB', ['count' => rtrim(rtrim(number_format($maxKb / 1024, 1), '0'), '.')]) : __(':count KB', ['count' => $maxKb]));
    $hint = collect([
        $types->isNotEmpty() ? $types->join(app()->getLocale() === 'ar' ? '، ' : ', ') : null,
        $size ? ($multiple ? __('up to :size each', ['size' => $size]) : __('up to :size', ['size' => $size])) : null,
        $multiple && $maxFiles ? __('at most :count files', ['count' => $maxFiles]) : null,
    ])->filter()->join(' · ');
@endphp
<span data-file-field class="block"
      @if ($maxKb) data-max-bytes="{{ $maxKb * 1024 }}" @endif
      @if ($maxFiles) data-max-files="{{ $maxFiles }}" @endif
      data-hint="{{ $hint }}">
    <x-ui.input type="file" :accept="$accept" :multiple="$multiple" {{ $attributes }} />
    @if ($hint !== '')
        <span class="mt-1 block text-xs font-normal text-subtle" data-file-hint>{{ $hint }}</span>
    @endif
</span>
