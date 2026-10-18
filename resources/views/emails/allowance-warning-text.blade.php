{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __(':organization has used :used of its :allowed :what', ['organization' => $organizationName, 'used' => number_format($used), 'allowed' => number_format($allowed), 'what' => $what]) !!}

@if ($percent >= 100)
{!! __('The allowance is used up. Further ones are refused until it renews or is raised.') !!}
@else
{!! __('That is :percent% of the allowance. Once it is used up, further ones are refused until it renews or is raised.', ['percent' => $percent]) !!}
@endif

{!! __('To raise it, reply to this email or open a ticket under Support.') !!}

{!! __('See your plan') !!}: {!! $url !!}
