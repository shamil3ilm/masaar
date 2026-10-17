<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\GoldenMaster;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Services\Billing;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineFallback;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Pipeline\Services\PipelineService;
use App\Domains\Webhook\Jobs\DeliverWebhook;
use App\Domains\Webhook\Models\Webhook;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\GoldenMaster;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Saudi issuing, end to end, against committed snapshots.
 *
 * The common issuing lifecycle - the organization lock, numbering, issued_at,
 * status and the invoice.issued webhook - is about to move out of
 * Submitter::generate into an issuer that knows no jurisdiction, leaving
 * ZATCA's counter, PIH, XML, XAdES signature, QR and chain entry as the Saudi
 * seal. The Saudi output has to come through that unchanged, byte for byte,
 * and this is what says whether it did.
 *
 * Every real way in is driven: PipelineService::submitInvoice with
 * auto_submit false, the portal's issue button over HTTP, Billing invoicing a
 * payment through the platform, and POST /api/compliance/sa/generate. The
 * documents cover a simplified and a standard invoice, a credit and a debit
 * note against an original, zero-rated and exempt lines, a document-level
 * discount across two categories, a foreign currency, prices that include
 * VAT, a branch signing with its own certificate, and an invoice sent with
 * no payment means.
 *
 * Per document, tests/Fixtures/golden/<case>/<label>.xml holds the signed XML
 * and <label>.json the hash, the decoded QR, invoice_number, icv, issued_at,
 * status, the chain entry and head, the entry point's own answer (the
 * PipelineResult array, the HTTP response, the portal's redirect and flash),
 * the invoice.* webhooks queued, and any filing requested.
 *
 * What is masked, and why: ECDSA signing draws a random nonce, so
 * ds:SignatureValue and QR tag 7 - the same value - differ on every run, and
 * with tag 7 so does the QR blob embedded in the XML. Exactly those are
 * masked; nothing else is. The signature is verified with OpenSSL against the
 * fixture certificate, tag 7 is asserted equal to ds:SignatureValue, the
 * embedded QR equal to qr_code, and the hash recomputed independently - the
 * hash removes the signature before digesting, so it is not masked. See
 * Tests\Fixtures\GoldenMaster for the details.
 *
 * Filing is outside what is pinned. OfflineFallback is replaced by a recorder,
 * so the portal and Billing paths - which file when the organization is
 * onboarded - reach no authority, and the snapshot records what they asked
 * for. The pipeline cases record that nothing was asked.
 *
 * Regenerate deliberately, and review the diff: GOLDEN_UPDATE=1 php artisan
 * test tests/Feature/Compliance/GoldenMaster. Rewritten cases report as
 * skipped.
 */
class SaudiIssuingGoldenTest extends TestCase
{
    use GoldenMaster;
    use RefreshDatabase;

    /** The instant every case is issued at. 12:15:42 in Riyadh. */
    private const NOW = '2026-09-15 09:15:42';

    private const BUYER_ADDRESS = [
        'street' => 'Olaya Street',
        'building_number' => '4321',
        'district' => 'Al Murooj',
        'city' => 'Riyadh',
        'postal_code' => '11564',
        'country_code' => 'SA',
    ];

    private Organization $seller;

    /** @var list<array{invoice_id: string, options: array<string, mixed>}> */
    private array $filings = [];

    /** The UUID series the next drafted document takes; setup used 1. */
    private int $series = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUuidSeries($this->series);
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));

        Queue::fake([DeliverWebhook::class]);
        Http::preventStrayRequests();

        $this->mock(OfflineFallback::class, function ($mock): void {
            $mock->shouldReceive('submit')->andReturnUsing(function (Invoice $invoice, array $options = []): array {
                $this->filings[] = ['invoice_id' => $invoice->id, 'options' => $options];

                return ['success' => true, 'submission_id' => null, 'state' => 'recorded-by-golden-master', 'errors' => [], 'warnings' => []];
            });
        });

        $this->seller = Organization::create([
            'name' => 'Acme Trading',
            'name_ar' => 'شركة أكمي للتجارة',
            'country' => 'SA',
            'vat_number' => '399999999900003',
            'cr_number' => '1010101010',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $this->storeCredentials(null, 'head-office');

        Webhook::withoutTenantScope(fn () => Webhook::create([
            'org_id' => $this->seller->id,
            'url' => 'https://erp.example.test/hooks',
            'secret' => 'golden-webhook-secret',
            'events' => ['*'],
            'is_active' => true,
        ]));
    }

    protected function tearDown(): void
    {
        Str::createUuidsNormally();

        parent::tearDown();
    }

    public function test_pipeline_simplified_invoice_matches_golden(): void
    {
        $this->viaPipeline('pipeline-simplified', 'invoice', $this->simplified('GM-SIM-1', [
            ['description' => 'Coffee beans 1kg', 'quantity' => 2, 'unit_price' => '45.50'],
            ['description' => 'Paper filters', 'quantity' => 3, 'unit_price' => '7.33'],
        ]));

        $this->finishGolden();
    }

    /**
     * Every other pipeline case names its payment means, so the code an ERP
     * caller's document gets when it sends none was pinned only incidentally,
     * by Billing's own invoice.
     */
    public function test_pipeline_default_payment_means_golden(): void
    {
        $this->viaPipeline('pipeline-default-payment-means', 'invoice', array_diff_key($this->simplified('GM-PM-1', [
            ['description' => 'Notebook A5', 'quantity' => 4, 'unit_price' => '12.25'],
        ]), ['payment_means_code' => true]));

        $this->finishGolden();
    }

    public function test_pipeline_standard_invoice_matches_golden(): void
    {
        $this->viaPipeline('pipeline-standard', 'invoice', $this->standard('GM-STD-1', [
            ['description' => 'Consulting, September', 'quantity' => 12, 'unit_price' => '350.00', 'unit_code' => 'HUR'],
            ['description' => 'Travel', 'quantity' => 1, 'unit_price' => '1250.75'],
        ]));

        $this->finishGolden();
    }

    public function test_pipeline_credit_note_matches_golden(): void
    {
        $this->viaPipeline('pipeline-credit-note', 'original', $this->standard('GM-CN-ORIG', [
            ['description' => 'Office chairs', 'quantity' => 10, 'unit_price' => '420.00'],
        ]));

        $this->viaPipeline('pipeline-credit-note', 'note', $this->standard('GM-CN-1', [
            ['description' => 'Office chairs returned', 'quantity' => 2, 'unit_price' => '420.00'],
        ], [
            'document_type' => 'credit_note',
            'billing_ref' => 'GM-CN-ORIG',
            'adjustment_reason' => 'Two chairs returned damaged',
        ]));

        $this->finishGolden();
    }

    public function test_pipeline_debit_note_matches_golden(): void
    {
        $this->viaPipeline('pipeline-debit-note', 'original', $this->simplified('GM-DN-ORIG', [
            ['description' => 'Delivery', 'quantity' => 1, 'unit_price' => '30.00'],
        ]));

        $this->viaPipeline('pipeline-debit-note', 'note', $this->simplified('GM-DN-1', [
            ['description' => 'Delivery surcharge, remote area', 'quantity' => 1, 'unit_price' => '15.00'],
        ], [
            'document_type' => 'debit_note',
            'billing_ref' => 'GM-DN-ORIG',
            'adjustment_reason' => 'Remote area surcharge not charged',
        ]));

        $this->finishGolden();
    }

    public function test_pipeline_zero_rated_and_exempt_lines(): void
    {
        $this->viaPipeline('pipeline-zero-exempt', 'invoice', $this->standard('GM-ZE-1', [
            ['description' => 'Standard-rated widgets', 'quantity' => 4, 'unit_price' => '25.00'],
            [
                'description' => 'Exported goods',
                'quantity' => 1,
                'unit_price' => '500.00',
                'tax_rate' => 0,
                'tax_category' => 'Z',
                'exempt_code' => 'VATEX-SA-34-3',
                'exempt_reason' => 'Exported goods',
            ],
            [
                'description' => 'Life insurance premium',
                'quantity' => 1,
                'unit_price' => '200.00',
                'tax_rate' => 0,
                'tax_category' => 'E',
                'exempt_code' => 'VATEX-SA-29-7',
                'exempt_reason' => 'Life insurance services',
            ],
        ]));

        $this->finishGolden();
    }

    public function test_pipeline_discount_across_two_categories(): void
    {
        $this->viaPipeline('pipeline-discount', 'invoice', $this->standard('GM-DISC-1', [
            ['description' => 'Widget', 'quantity' => 3, 'unit_price' => '19.99'],
            ['description' => 'Bolt', 'quantity' => 7, 'unit_price' => '4.33'],
            [
                'description' => 'Exported goods',
                'quantity' => 1,
                'unit_price' => '50.00',
                'tax_rate' => 0,
                'tax_category' => 'Z',
                'exempt_code' => 'VATEX-SA-34-3',
                'exempt_reason' => 'Exported goods',
            ],
        ], ['discount_amount' => '10.00']));

        $this->finishGolden();
    }

    public function test_pipeline_foreign_currency_matches_golden(): void
    {
        $this->viaPipeline('pipeline-foreign-currency', 'invoice', $this->standard('GM-USD-1', [
            ['description' => 'Software licence, annual', 'quantity' => 1, 'unit_price' => '1999.00'],
        ], ['currency' => 'USD', 'exchange_rate' => '3.750000']));

        $this->finishGolden();
    }

    public function test_pipeline_prices_including_vat_match(): void
    {
        $this->viaPipeline('pipeline-vat-inclusive', 'invoice', $this->simplified('GM-INC-1', [
            ['description' => 'Lunch set', 'quantity' => 3, 'unit_price' => '34.50'],
            ['description' => 'Bottled water', 'quantity' => 2, 'unit_price' => '2.30'],
        ], ['prices_include_vat' => true]));

        $this->finishGolden();
    }

    public function test_pipeline_branch_signs_with_own_key(): void
    {
        $branch = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->seller->id,
            'name' => 'Jeddah Branch',
            'device_serial' => 'EGS-GOLDEN-JED',
            'industry' => 'Retail',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
        ]));

        $this->storeCredentials($branch->id, 'branch');

        $this->viaPipeline('pipeline-branch', 'invoice', $this->simplified('GM-JED-1', [
            ['description' => 'Dates gift box', 'quantity' => 1, 'unit_price' => '120.00'],
        ]), $branch->id, 'branch');

        $this->finishGolden();
    }

    public function test_portal_issue_matches_golden(): void
    {
        $member = User::factory()->create();
        $member->organizations()->attach($this->seller->id, ['role' => 'member', 'status' => 'active']);

        $invoice = $this->draft($this->simplified('GM-PORTAL-1', [
            ['description' => 'Printer paper A4', 'quantity' => 5, 'unit_price' => '18.00'],
        ]));

        $context = $this->observe(function () use ($member, $invoice): array {
            $response = $this->asRequest(fn () => $this->actingAs($member)
                ->withSession(['_token' => 'golden-token'])
                ->post('/portal/invoices/'.$invoice->id.'/issue', ['_token' => 'golden-token']));

            $session = app('session.store');

            return [
                'entry_point' => 'POST /portal/invoices/{id}/issue',
                'response' => [
                    'status' => $response->getStatusCode(),
                    'location' => parse_url((string) $response->headers->get('Location'), PHP_URL_PATH),
                    'flash' => array_filter([
                        'success' => $session->get('success'),
                        'warning' => $session->get('warning'),
                        'error' => $session->get('error'),
                    ]),
                ],
            ];
        });

        $this->assertGolden('portal-issue', 'invoice', $invoice, 'head-office', $context);
        $this->finishGolden();
    }

    public function test_billing_self_invoice_matches_golden(): void
    {
        $buyer = Organization::create([
            'name' => 'Beta Industries',
            'name_ar' => 'صناعات بيتا',
            'country' => 'SA',
            'vat_number' => '399999999800003',
            'street' => 'Olaya Street',
            'building_number' => '4321',
            'district' => 'Al Murooj',
            'city' => 'Riyadh',
            'postal_code' => '11564',
        ]);

        License::createWithCredentials([
            'org_id' => $buyer->id,
            'organization_name' => 'Beta Industries',
            'contact_email' => 'ops@beta.test',
            'tier' => LicenseTier::Starter,
            'status' => LicenseStatus::Active,
            'expires_at' => now()->addDays(10),
        ]);

        $operator = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);
        config(['billing.organization_id' => $this->seller->id]);

        $this->useUuidSeries(++$this->series);
        $payment = null;

        $context = $this->observe(function () use ($buyer, $operator, &$payment): array {
            $payment = app(Billing::class)->record(
                $buyer,
                LicenseTier::Starter,
                12,
                '6000',
                PaymentMethod::BankTransfer,
                'TRF-GOLDEN-1',
                CarbonImmutable::now(),
                null,
                (string) $operator->id,
            );

            return [
                'entry_point' => 'Billing::record',
                'payment' => [
                    'amount' => (string) $payment->amount,
                    'vat_amount' => (string) $payment->vat_amount,
                    'total' => (string) $payment->total,
                    'period_start' => $payment->period_start->toDateString(),
                    'period_end' => $payment->period_end->toDateString(),
                    'invoice_error' => $payment->invoice_error,
                ],
            ];
        });

        $this->assertNull($payment->invoice_error, 'Billing did not issue: '.$payment->invoice_error);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($payment->invoice_id));

        $this->assertGolden('billing-self-invoice', 'invoice', $invoice, 'head-office', $context);
        $this->finishGolden();
    }

    public function test_api_generate_matches_golden(): void
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->seller->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->seller->id, 'role' => 'admin'])->fromUser($user);

        $invoice = $this->draft($this->standard('GM-API-1', [
            ['description' => 'Maintenance contract, Q4', 'quantity' => 1, 'unit_price' => '7800.00'],
        ]));

        $context = $this->observe(function () use ($token, $invoice): array {
            $response = $this->withToken($token)->postJson('/api/compliance/sa/generate/'.$invoice->id);

            return [
                'entry_point' => 'POST /api/compliance/sa/generate/{invoiceId}',
                'response' => ['status' => $response->getStatusCode(), 'body' => $response->json()],
            ];
        });

        $this->assertGolden('api-generate', 'invoice', $invoice, 'head-office', $context);
        $this->finishGolden();
    }

    /**
     * Issue through the ERP pipeline without filing, and check the result.
     *
     * @param  array<string, mixed>  $data
     */
    private function viaPipeline(string $case, string $label, array $data, ?string $branchId = null, string $device = 'head-office'): void
    {
        $this->useUuidSeries(++$this->series);
        $result = null;

        $context = $this->observe(function () use ($data, $branchId, &$result): array {
            $result = app(PipelineService::class)->submitInvoice([...$data, 'auto_submit' => false], $this->seller->id, $branchId);

            return ['entry_point' => 'PipelineService::submitInvoice', 'pipeline_result' => $result];
        });

        $this->assertSame([], $result['errors'], "{$case}/{$label}: the pipeline reported errors");

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($result['invoice_id']));

        $this->assertGolden($case, $label, $invoice, $device, $context);
    }

    /**
     * Run an entry point and add what it queued and asked to file.
     *
     * @param  callable(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function observe(callable $call): array
    {
        $hooks = $this->webhooksSoFar();
        $filings = count($this->filings);

        $context = $call();

        return [
            ...$context,
            'webhooks' => $this->webhooksSince($hooks),
            'filing_requests' => array_slice($this->filings, $filings),
        ];
    }

    /**
     * A draft for the entry points that issue an invoice already drafted.
     *
     * @param  array<string, mixed>  $data
     */
    private function draft(array $data): Invoice
    {
        $this->useUuidSeries(++$this->series);

        return app(InvoiceDrafter::class)->draft($data, $this->seller->id);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function standard(string $number, array $lines, array $overrides = []): array
    {
        return [
            'invoice_number' => $number,
            'erp_reference_id' => 'ERP-'.$number,
            'type' => 'standard',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'payment_means_code' => '30',
            'buyer_name' => 'Beta Industries',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => self::BUYER_ADDRESS,
            'lines' => $lines,
            ...$overrides,
        ];
    }

    /**
     * A walk-in sale: no buyer name, address or identifier.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function simplified(string $number, array $lines, array $overrides = []): array
    {
        return [
            'invoice_number' => $number,
            'erp_reference_id' => 'ERP-'.$number,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'payment_means_code' => '10',
            'lines' => $lines,
            ...$overrides,
        ];
    }

    private function storeCredentials(?string $branchId, string $device): void
    {
        $credentials = $this->goldenCredentials($device);

        app(CredentialStore::class)->put($this->seller->id, $branchId, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'],
            'pcsid' => $credentials['certificate'],
            'secret' => 'golden-'.$device.'-secret',
        ]);
    }
}
