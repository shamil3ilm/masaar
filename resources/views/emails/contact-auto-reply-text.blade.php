{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
@foreach ($parts as [$language, $text])
{!! __('We have your message', [], $language) !!}

{!! $text !!}
@if (! $loop->last)

---

@endif
@endforeach
