{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __('Waiting for an approver') !!}.

{!! __('In :organization this waits for a second person to approve it, and nobody besides the person who asked can. It stays waiting until another owner or admin approves it. Add one under Team, or turn the approval off under Settings, Approvals, and send it again.', ['organization' => $organizationName]) !!}

{!! $summary !!}

{!! __('Open approvals') !!}: {!! $url !!}
