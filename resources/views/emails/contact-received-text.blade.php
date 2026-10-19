{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __('A message from the website') !!}.

{!! $contact->name !!} <{!! $contact->email !!}>
{!! $contact->topic->label() !!}@if ($contact->company) · {!! $contact->company !!}@endif @if ($contact->country) · {!! $contact->country !!}@endif


{!! $contact->message !!}

{!! __('Reply to this email to answer them, then mark it replied in the inbox.') !!}

{!! $url !!}
