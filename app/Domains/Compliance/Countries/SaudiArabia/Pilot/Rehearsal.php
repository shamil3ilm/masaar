<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Pilot;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineQueue;
use App\Domains\Compliance\Countries\SaudiArabia\Models\OfflineItem;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\InvoiceRules;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\TlvEncoder;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\AdjustmentDraft;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\FilingClearance;
use App\Domains\Organization\Services\TenantResolver;
use App\Support\CountryClock;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * The whole ZATCA journey a pilot takes, run against the developer portal
 * (sandbox) for the rehearsal organization, through the platform's own code.
 *
 * Onboarding is OrganizationOnboarding, as the portal's three steps call it.
 * Every document is checked by the country's invoice rules as the API and
 * the portal form check it, drafted by InvoiceDrafter and issued and filed
 * by the compliance engine - nothing here signs, hashes or files on its own.
 * What it adds is the checking: each document's final state, the QR tags,
 * the chain, the offline queue, the PDFs and the audit trail.
 *
 * Each step is a Check. Onboarding stops at its first failure, since every
 * later step needs a production certificate; the documents go on after one
 * fails, except the notes, which need their original accepted.
 */
class Rehearsal
{
    /** The developer portal's published OTP: it accepts this one for any request. */
    public const OTP = '123345';

    /** Every document says it is not a tax record, in Arabic first (ArabicText). */
    public const NOTE = 'تجربة تشغيلية في بيئة الاختبار، وليست سجلاً ضريبياً. Rehearsal in the sandbox, not a tax record.';

    /** Seconds between looks at a document filed by a queue worker. */
    private const POLL_SECONDS = 2;

    /** The audit entry each onboarding step leaves when it succeeds (OnboardingRecord). */
    private const AUDITED_STEPS = [
        'compliance certificate' => 'zatca.onboarding.ccsid',
        'compliance checks' => 'zatca.onboarding.compliance',
        'production certificate' => 'zatca.onboarding.pcsid',
    ];

    /** What a PDF carries once PdfStamp has sealed it (its signature's SubFilter). */
    private const PDF_STAMP = '/ETSI.CAdES.detached';

    public function __construct(
        private readonly OrganizationOnboarding $onboarding,
        private readonly FilingClearance $clearance,
        private readonly InvoiceRules $rules,
        private readonly InvoiceDrafter $drafter,
        private readonly AdjustmentDraft $adjustments,
        private readonly ComplianceEngine $engine,
        private readonly InvoicePdf $pdf,
        private readonly TlvEncoder $tlv,
        private readonly OfflineQueue $offline,
        private readonly TenantResolver $tenant,
        private readonly ValidationFactory $validation,
        private readonly Kernel $artisan,
    ) {}

    /**
     * @param  int  $wait  seconds to wait for a document a queue worker files
     * @param  (Closure(Check): void)|null  $progress  told of each check as it is made
     * @return list<Check>
     */
    public function run(Organization $organization, string $run, int $wait, ?Closure $progress = null): array
    {
        $checks = [];
        $record = function (Check $check) use (&$checks, $progress): bool {
            $checks[] = $check;
            $progress?->__invoke($check);

            return $check->passed;
        };

        $started = now()->subSecond();

        $record($this->profile($organization));

        if (! $record($this->ccsid($organization)) || ! $record($this->complianceChecks($organization)) || ! $record($this->pcsid($organization))) {
            $record($this->auditTrail($organization, $started));

            return $checks;
        }

        $documents = $this->documents($organization, $run, $wait, $record);

        $record($this->pdfs($organization, $documents));
        $record($this->qr($organization, $documents));
        $record($this->chain($organization));
        $record($this->offlineQueue($organization, $documents));
        $record($this->auditTrail($organization, $started));

        return $checks;
    }

    /**
     * The QR's tags, read back: tags 1 to 8 on every document and the
     * stamp's tag 9 on a simplified one (ZATCA Security Features standard,
     * QR code), the seller's name exactly as it is registered (tag 1, the
     * XML's RegistrationName) and its VAT number (tag 2).
     */
    public function qrProblem(Organization $organization, Invoice $invoice): ?string
    {
        $tags = $this->tlv->decode((string) $invoice->qr_code);
        $wanted = $invoice->requiresClearance() ? range(1, 8) : range(1, 9);
        $missing = array_diff($wanted, array_keys(array_filter($tags, fn (string $value): bool => $value !== '')));

        return match (true) {
            $missing !== [] => __(':number: QR tags :tags are missing.', ['number' => $invoice->invoice_number, 'tags' => implode(', ', $missing)]),
            $tags[1] !== $organization->documentName() => __(':number: QR tag 1 reads ":read", not the registered name ":name".', ['number' => $invoice->invoice_number, 'read' => $tags[1], 'name' => $organization->documentName()]),
            $tags[2] !== (string) $organization->vat_number => __(':number: QR tag 2 is not the VAT number.', ['number' => $invoice->invoice_number]),
            default => null,
        };
    }

    /**
     * The onboarding steps in the organization's audit log since $since: a
     * platform admin has to be able to see that they happened, and how.
     */
    public function auditTrail(Organization $organization, CarbonInterface $since): Check
    {
        $actions = AuditLog::query()
            ->where('org_id', $organization->id)
            ->where('created_at', '>=', $since)
            ->pluck('action');

        $missing = array_keys(array_filter(
            self::AUDITED_STEPS,
            fn (string $action): bool => ! $actions->contains($action),
        ));

        return $missing === []
            ? Check::pass('audit', __('Onboarding steps in the audit log'), __('Each step has an entry.'))
            : Check::fail('audit', __('Onboarding steps in the audit log'), __('No entry for: :steps.', ['steps' => implode(', ', $missing)]), __('Onboarding must write an audit entry for each step, failures included.'));
    }

    private function profile(Organization $organization): Check
    {
        $name = __('Company profile');

        if (! $organization->hasCompleteZatcaProfile()) {
            return Check::fail('profile', $name, __('The VAT number, CRN or national address is incomplete.'), __('Complete the rehearsal organization\'s company page.'));
        }

        return str_contains((string) $organization->name_ar, 'ة')
            ? Check::pass('profile', $name, __('VAT number, CRN :crn, national address and Arabic legal name ":name".', ['crn' => $organization->cr_number, 'name' => $organization->name_ar]))
            : Check::fail('profile', $name, __('The Arabic legal name has no ة, so a changed name would not show.'), __('Give the rehearsal organization an Arabic legal name with ة in it.'));
    }

    private function ccsid(Organization $organization): Check
    {
        $name = __('Compliance certificate (OTP)');

        try {
            $this->clearance->assertCleared($organization);
            $this->onboarding->requestCcsid($organization, self::OTP, [
                'common_name' => $this->onboarding->deviceName($organization),
                'serial_number' => $this->onboarding->deviceSerial($organization),
                'organization_unit' => 'Rehearsal',
                'industry' => 'General',
            ]);
        } catch (Throwable $e) {
            return Check::fail('ccsid', $name, $e->getMessage(), __('Read the reason: a refused OTP or an incomplete profile stops here.'));
        }

        return Check::pass('ccsid', $name, __('Issued for the sandbox OTP.'));
    }

    private function complianceChecks(Organization $organization): Check
    {
        $name = __('Six compliance documents');

        try {
            $result = $this->onboarding->runComplianceCheck($organization);
        } catch (Throwable $e) {
            return Check::fail('compliance', $name, $e->getMessage());
        }

        if ($result['passed']) {
            return Check::pass('compliance', $name, __('All six passed.'));
        }

        $refused = [];

        foreach ((array) $result['results'] as $type => $outcome) {
            if (! ($outcome['passed'] ?? false)) {
                $refused[] = $type.': '.mb_strimwidth(implode('; ', FatooraResponse::messages((array) ($outcome['errors'] ?? []))), 0, 160, '...');
            }
        }

        return Check::fail('compliance', $name, implode(' | ', $refused), __('ZATCA refuses a production certificate until all six pass: fix the documents first.'));
    }

    private function pcsid(Organization $organization): Check
    {
        $name = __('Production certificate');

        try {
            $this->onboarding->requestPcsid($organization);
        } catch (Throwable $e) {
            return Check::fail('pcsid', $name, $e->getMessage());
        }

        return $this->engine->jurisdictionFor($organization->refresh())->transmission()->readyToFile($organization)
            ? Check::pass('pcsid', $name, __('Issued; the organization files with ZATCA.'))
            : Check::fail('pcsid', $name, __('Issued, but the organization is still not ready to file.'));
    }

    /**
     * The documents a pilot issues on the first day, each filed and waited
     * for, the notes against originals ZATCA accepted.
     *
     * @param  Closure(Check): bool  $record
     * @return array<string, Invoice> the ones issued, by key
     */
    private function documents(Organization $organization, string $run, int $wait, Closure $record): array
    {
        $issued = [];
        $today = CountryClock::now($organization->country)->toDateString();
        $line = fn (string $price, string $quantity = '1'): array => [
            'description' => 'Rehearsal item', 'description_ar' => 'صنف تجريبي',
            'quantity' => $quantity, 'unit_price' => $price, 'tax_rate' => '15',
        ];
        $buyer = [
            'buyer_name' => 'Rehearsal Buyer Company',
            'buyer_name_ar' => 'شركة المشتري التجريبية',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => [
                'street' => 'Salah Al-Din', 'building_number' => '1111', 'district' => 'Al Murooj',
                'city' => 'Riyadh', 'postal_code' => '12222', 'country_code' => 'SA',
            ],
        ];
        $simplified = ['type' => 'simplified', 'document_type' => 'invoice', 'issue_date' => $today, 'payment_means_code' => '10'];
        $standard = ['type' => 'standard', 'document_type' => 'invoice', 'issue_date' => $today, 'supply_date' => $today, 'payment_means_code' => '30', ...$buyer];

        $plan = [
            'simplified' => [__('Simplified invoice, reported'), fn (): array => [...$simplified, 'lines' => [$line('50.00', '2')]]],
            'standard' => [__('Standard invoice with supply date, cleared'), fn (): array => [...$standard, 'lines' => [$line('1000.00')]]],
            'credit_note' => [__('Credit note against the standard invoice'), fn (array $issued): array => $this->note($issued['standard'] ?? null, DocumentType::CreditNote, $today, 'In case of goods or services refund.')],
            'debit_note' => [__('Debit note against the simplified invoice'), fn (array $issued): array => $this->note($issued['simplified'] ?? null, DocumentType::DebitNote, $today, 'Amendment of the supply value which is pre-agreed upon between the supplier and consumer', [$line('5.00')])],
            'zero_rated' => [__('Zero-rated line with its exemption code'), fn (): array => [...$standard, 'lines' => [
                $line('200.00'),
                ['description' => 'Medicines', 'description_ar' => 'أدوية', 'quantity' => '1', 'unit_price' => '300.00', 'tax_rate' => '0', 'exempt_code' => 'VATEX-SA-35'],
            ]]],
            'discount' => [__('Invoice with a discount'), fn (): array => [...$simplified, 'discount_amount' => '10.00', 'lines' => [$line('100.00')]]],
            'foreign_currency' => [__('Foreign-currency invoice'), fn (): array => [...$standard, 'currency' => 'USD', 'exchange_rate' => '3.75', 'lines' => [$line('400.00')]]],
        ];

        foreach ($plan as $key => [$name, $data]) {
            try {
                $invoice = $this->issue($organization, $data($issued), $run, $key);
            } catch (ValidationException $e) {
                $record(Check::fail($key, $name, __('Refused before issue: :errors', ['errors' => implode(' ', $e->validator->errors()->all())])));

                continue;
            } catch (Throwable $e) {
                $record(Check::fail($key, $name, $e->getMessage()));

                continue;
            }

            $invoice = $this->settled($invoice, $wait);
            $issued[$key] = $invoice;
            $record($this->verdict($key, $name, $invoice));
        }

        return $issued;
    }

    /**
     * A note's fields, started from its original as the portal starts them
     * (AdjustmentDraft), once ZATCA accepted the original.
     *
     * @param  list<array<string, string>>|null  $lines  a debit note's charge; a credit note credits the original's lines
     * @return array<string, mixed>
     */
    private function note(?Invoice $original, DocumentType $type, string $today, string $reason, ?array $lines = null): array
    {
        if ($original === null || $original->status !== InvoiceStatus::Accepted) {
            throw new \DomainException(__('Its original was not accepted by ZATCA, so no note was issued against it.'));
        }

        $fields = $this->adjustments->fields($original->loadMissing(['lines', 'charges']), $type);

        return [...$fields, 'issue_date' => $today, 'adjustment_reason' => $reason, ...($lines === null ? [] : ['lines' => $lines])];
    }

    /**
     * Checked by the country's rules as the API and the portal check a
     * request, drafted, then issued and filed by the engine.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function issue(Organization $organization, array $data, string $run, string $key): Invoice
    {
        $invoice = $this->tenant->runAs((string) $organization->id, function () use ($organization, $data, $run, $key): Invoice {
            $request = Request::create('/', 'POST', [...$data, 'notes' => self::NOTE]);
            $request->merge($this->rules->defaults($request));

            $validator = $this->validation->make($request->all(), $this->rules->rules($request), $this->rules->messages());
            $validator->after(fn (Validator $validator) => $this->rules->after($validator, $request));
            $validated = $validator->validate();

            return $this->drafter->draft(
                [...$this->rules->draft($validated), 'erp_reference_id' => "rehearsal-{$run}-{$key}"],
                (string) $organization->id,
                countryFields: $this->rules->countryFields($validated),
            );
        });

        $outcome = $this->engine->issue($invoice, $organization, new IssueOptions(file: true));

        if (! $outcome->issued) {
            throw new \DomainException($outcome->errors[0] ?? __('The invoice could not be issued.'));
        }

        return $invoice->refresh();
    }

    /** A simplified document is filed by a queue worker: waited for, up to $wait seconds. */
    private function settled(Invoice $invoice, int $wait): Invoice
    {
        for ($waited = 0; ! $this->final($invoice->refresh()) && $waited < $wait; $waited += self::POLL_SECONDS) {
            Sleep::for(self::POLL_SECONDS)->seconds();
        }

        return $invoice;
    }

    private function final(Invoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::Accepted, InvoiceStatus::Rejected], true);
    }

    private function verdict(string $key, string $name, Invoice $invoice): Check
    {
        $response = (array) ($invoice->zatca_response ?? []);
        $warnings = FatooraResponse::messages((array) ($response['warnings'] ?? []));
        $number = (string) $invoice->invoice_number;

        if ($invoice->status !== InvoiceStatus::Accepted) {
            $errors = FatooraResponse::messages((array) ($response['errors'] ?? []));

            return Check::fail($key, $name, __(':number is :status.', ['number' => $number, 'status' => $invoice->status->value]).($errors === [] ? '' : ' '.mb_strimwidth(implode('; ', $errors), 0, 200, '...')),
                $invoice->status === InvoiceStatus::Rejected ? __('Read ZATCA\'s reasons on the invoice page.') : __('Still waiting: check the queue workers and the offline queue.'));
        }

        if ($invoice->requiresClearance() && $invoice->cleared_xml === null) {
            return Check::fail($key, $name, __(':number is accepted, but ZATCA\'s cleared copy was not kept.', ['number' => $number]));
        }

        $state = $invoice->requiresClearance() ? __('cleared') : __('reported');

        return Check::pass($key, $name, $number.': '.$state.($warnings === [] ? '' : ' - '.__('warnings: :warnings', ['warnings' => mb_strimwidth(implode('; ', $warnings), 0, 200, '...')])));
    }

    /**
     * @param  array<string, Invoice>  $documents
     */
    /**
     * Both render. In the sandbox the PDF may come unstamped: the developer
     * portal issues every caller one certificate whose key nobody outside
     * ZATCA holds, and Presentation::sealPdf() then gives it unstamped.
     * Anywhere else it must carry the device's stamp.
     */
    private function pdfs(Organization $organization, array $documents): Check
    {
        $name = __('PDFs, A4 and 80mm');
        $stampRequired = FatooraEnvironment::of($organization) !== FatooraEnvironment::SANDBOX;
        $made = [];

        foreach ([['standard', PaperSize::A4], ['simplified', PaperSize::Roll80]] as [$key, $paper]) {
            $invoice = $documents[$key] ?? null;

            if ($invoice === null) {
                return Check::fail('pdf', $name, __('No :key invoice to print.', ['key' => $key]));
            }

            try {
                $pdf = $this->pdf->render($invoice, $organization, $paper);
            } catch (Throwable $e) {
                return Check::fail('pdf', $name, $invoice->invoice_number.' ('.$paper->value.'): '.$e->getMessage(), __('The PDF is what the buyer is given: it must render with the onboarded certificate.'));
            }

            if (! str_starts_with($pdf, '%PDF')) {
                return Check::fail('pdf', $name, __(':number (:paper) is not a PDF.', ['number' => $invoice->invoice_number, 'paper' => $paper->value]));
            }

            $stamped = str_contains($pdf, self::PDF_STAMP);

            if ($stampRequired && ! $stamped) {
                return Check::fail('pdf', $name, __(':number (:paper) is not stamped with the device\'s certificate.', ['number' => $invoice->invoice_number, 'paper' => $paper->value]), __('The PDF is what the buyer is given: it must render with the onboarded certificate.'));
            }

            $made[] = $invoice->invoice_number.' '.$paper->value.' ('.($stamped ? __('stamped') : __('unstamped, as the sandbox gives it')).')';
        }

        return Check::pass('pdf', $name, implode(', ', $made));
    }

    /**
     * @param  array<string, Invoice>  $documents
     */
    private function qr(Organization $organization, array $documents): Check
    {
        $name = __('QR tags');

        if ($documents === []) {
            return Check::fail('qr', $name, __('No document was issued.'));
        }

        $problems = array_filter(array_map(fn (Invoice $invoice): ?string => $this->qrProblem($organization, $invoice), $documents));

        return $problems === []
            ? Check::pass('qr', $name, __('Tags 1 to 8 on every document, 9 on simplified ones; seller name and VAT number as registered.'))
            : Check::fail('qr', $name, implode(' ', $problems), __('The QR must carry the registered name and VAT number unchanged.'));
    }

    private function chain(Organization $organization): Check
    {
        $name = __('Hash chain');
        $failed = $this->artisan->call('fatoora:verify-hash-chain', ['--organization' => (string) $organization->id]) !== 0;

        if (! $failed) {
            return Check::pass('chain', $name, __('Counters consecutive and every PIH the hash before it.'));
        }

        $breaks = array_values(array_filter(array_map('trim', explode("\n", $this->artisan->output())), fn (string $line): bool => str_contains($line, 'ICV')));

        return Check::fail('chain', $name, mb_strimwidth(implode(' ', $breaks), 0, 300, '...'), __('A broken chain cannot be repaired: stop and investigate before the pilot.'));
    }

    /**
     * @param  array<string, Invoice>  $documents
     */
    private function offlineQueue(Organization $organization, array $documents): Check
    {
        $name = __('Offline queue');
        $queued = OfflineItem::withoutTenantScope(fn () => OfflineItem::query()
            ->whereIn('invoice_id', array_map(fn (Invoice $invoice): string => (string) $invoice->id, array_values($documents)))
            ->count());
        $waiting = $this->offline->getQueueSize((string) $organization->id);

        return $queued === 0 && $waiting === 0
            ? Check::pass('offline', $name, __('Nothing was queued offline.'))
            : Check::fail('offline', $name, __(':queued of this run\'s documents went through the offline queue; :waiting still wait.', ['queued' => $queued, 'waiting' => $waiting]),
                __('The gateway was judged unreachable while it answered: check the connectivity probe, and the scheduler that drains the queue.'));
    }
}
