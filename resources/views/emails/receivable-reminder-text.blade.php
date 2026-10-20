{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". In both languages, the reader's first. --}}
@foreach ($locales as $locale)
{!! $kind === 'overdue' ? __('Invoices to :organization are past due', ['organization' => $organizationName], $locale) : __('Invoices to :organization fall due soon', ['organization' => $organizationName], $locale) !!}

{!! $kind === 'overdue' ? __('These invoices are past their due date and still unpaid. Please pay them, quoting the invoice number.', [], $locale) : __('These invoices fall due soon. Please pay them by their due date, quoting the invoice number.', [], $locale) !!}

@foreach ($invoices as $invoice)
- {!! $invoice['number'] !!}: {!! $invoice['balance'] !!} {!! $invoice['currency'] !!} ({!! __('Due', [], $locale) !!} {!! $invoice['due_date'] !!})
@endforeach
@if ($accounts !== [])

{!! __('Pay by bank transfer to:', [], $locale) !!}
@foreach ($accounts as $account)
{!! $account['bank_name'] !!} - {!! $account['account_name'] !!} - {!! $account['iban'] !!}
@endforeach
@endif

{!! __('See your statement', [], $locale) !!}: {!! $statementUrl !!}
@if (! $loop->last)

----

@endif
@endforeach
