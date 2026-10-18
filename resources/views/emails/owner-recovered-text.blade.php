{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __('Approvals were changed from the server') !!}.

{!! __('Someone with access to the server ran the recovery command for a locked-out owner. It is on the security record.') !!}

{!! $done === \App\Domains\Platform\Mail\OwnerRecoveredMail::ENABLED_BYPASS ? __(':setting: turned back on.', ['setting' => __('Allow bypass when no other approver exists')]) : __('Approved from the server: :summary', ['summary' => $summary]) !!}

{!! __('Why: :reason', ['reason' => $reason]) !!}
{!! __('When: :time', ['time' => $at]) !!}

{!! __('If you did not ask for this, treat the server as compromised and review its access now.') !!}

{!! $policiesUrl !!}
