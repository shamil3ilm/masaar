{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __('Appoint your e-invoicing service provider by :date', ['date' => $deadline]) !!}

{!! __(':organization has no accredited service provider recorded as appointed. In the UAE, every business in scope appoints one provider for sending and receiving e-invoices, by the last date its revenue band sets.', ['organization' => $organizationName]) !!}

{!! $days === 1 ? __('That date is tomorrow.') : __('That date is in :days days.', ['days' => $days]) !!} {!! __('Once you have appointed one through EmaraTax, record it on your company page.') !!}

{!! __('Record your service provider') !!}: {!! $url !!}
