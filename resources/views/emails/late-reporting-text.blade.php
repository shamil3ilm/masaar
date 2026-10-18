{{-- Plain text: unescaped. Arabic first, then English (LateReportingMail). --}}
@if ($resolved)
تم الإبلاغ عن الفواتير المتأخرة لدى {!! $organizationName !!}.
وصلت الفواتير المبسطة أدناه إلى هيئة الزكاة والضريبة والجمارك بعد انقضاء مهلة الأربع والعشرين ساعة. أبلغ الهيئة مرة أخرى عبر النموذج المخصص في موقعها الإلكتروني (zatca.gov.sa)، ثم سجّل ذلك في صفحة «الإبلاغ المتأخر» في البوابة.

Late invoices now reported for {!! $organizationName !!}.
The simplified invoices below have now reached ZATCA, after their twenty-four hours had passed. Notify ZATCA again through the dedicated form on its website (zatca.gov.sa), then record it on the Late reporting page of the portal.
@else
فواتير لم يُبلَّغ عنها خلال 24 ساعة لدى {!! $organizationName !!}.
لم يتمكن النظام من الإبلاغ عن الفواتير المبسطة أدناه خلال 24 ساعة من إصدارها، وما زال يعيد المحاولة ويحتفظ بسجل لكل محاولة. أبلغ الهيئة الآن عبر النموذج المخصص في موقعها الإلكتروني (zatca.gov.sa)، ثم سجّل ذلك في صفحة «الإبلاغ المتأخر» في البوابة.

Invoices not reported within 24 hours for {!! $organizationName !!}.
The simplified invoices below could not be reported to ZATCA within 24 hours of being issued. The platform keeps trying and keeps a record of every attempt. Notify ZATCA now through the dedicated form on its website (zatca.gov.sa), then record it on the Late reporting page of the portal.
@endif

@foreach ($documents as $document)
{!! $document['number'] !!}  {!! $document['issued_at'] !!} UTC
@endforeach

الإبلاغ المتأخر / Late reporting: {!! $url !!}
