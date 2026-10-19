{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __('A scheduled invoice was not made: :name', ['name' => $scheduleName]) !!}

{!! __('The invoice for :start to :end could not be made, and it has been tried several times. No invoice was issued for this period.', ['start' => $periodStart, 'end' => $periodEnd]) !!}

{!! __('The reason given: :reason', ['reason' => $error]) !!}

{!! __('Open the schedule') !!}: {!! $url !!}
