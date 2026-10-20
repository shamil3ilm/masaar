{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __('Expected bills not entered') !!}.

{!! __('These recurring costs were expected and their bills are still drafts. Record each when its bill comes, or void it if none will.') !!}

@foreach ($bills as $bill)
- {!! $bill['supplier'] !!}: {!! $bill['description'] !!}. {!! __('Expected on :date: :amount', ['date' => $bill['expected_on'], 'amount' => $bill['amount']]) !!}
  {!! $bill['url'] !!}
@endforeach

{!! $url !!}
