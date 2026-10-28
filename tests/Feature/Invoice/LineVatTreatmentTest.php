<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Each line's VAT treatment - its category and, off the standard rate, its
 * reason from ZATCA's code list - chosen on the portal form and checked by
 * the Saudi rules, citing them.
 *
 * A pharmacy sells zero-rated medicines (VATEX-SA-35) and standard-rated
 * cosmetics on one receipt: the totals and the VAT by category are exact,
 * and the form's preview shows the same figures the draft is saved with.
 */
class LineVatTreatmentTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'vat-treatment-token';

    private Organization $pharmacy;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pharmacy = Organization::create(['name' => 'Al Dawaa Pharmacy', 'country' => 'SA']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->pharmacy->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_a_mixed_pharmacy_basket_adds_up(): void
    {
        $this->submit($this->basket())->assertSessionHasNoErrors()->assertRedirect();

        $invoice = Invoice::acrossTenants()->with('lines')->where('invoice_number', 'PHARMA-1')->firstOrFail();

        // 38.25 + 189.90 zero-rated, 129.00 at 15%; the discount of 10.00
        // is shared 6.39 / 3.61, so the VAT is 15% of 125.39.
        $this->assertSame('357.15', (string) $invoice->subtotal);
        $this->assertSame('18.81', (string) $invoice->tax_amount);
        $this->assertSame('365.96', (string) $invoice->total);
        $this->assertSame(['Z', 'Z', 'S'], $invoice->lines->pluck('tax_category')->all());
        $this->assertSame('Medicines and medical equipment', $invoice->lines[0]->exempt_reason);
        $this->assertSame('19.35', (string) $invoice->lines[2]->tax_amount);
    }

    public function test_the_preview_matches_the_draft(): void
    {
        $preview = $this->asRequest(fn (): TestResponse => $this->actingAs($this->member)
            ->withSession(['_token' => self::TOKEN])
            ->postJson('/portal/invoices/preview', $this->basket()))
            ->assertOk()
            ->json();

        $this->assertSame(['18.81', '365.96'], [$preview['tax'], $preview['total']]);
        $this->assertSame(['0.00', '0.00', '19.35'], array_column($preview['lines'], 'tax'));
        $this->assertSame([
            ['code' => 'Z', 'label' => 'Zero-rated', 'rate' => '0.00', 'reasons' => ['VATEX-SA-35'], 'base' => '221.76', 'tax' => '0.00'],
            ['code' => 'S', 'label' => 'Standard rate (15%)', 'rate' => '15.00', 'reasons' => [], 'base' => '125.39', 'tax' => '18.81'],
        ], $preview['categories']);
    }

    public function test_the_form_offers_the_official_reasons(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->member)->get('/portal/invoices/create')
            ->assertOk()
            ->assertSee('name="lines[0][tax_category]"', false)
            ->assertSee('VATEX-SA-35 · Medicines and medical equipment · الأدوية والمعدات الطبية')
            ->assertSee('data-preview-url', false));
    }

    public function test_a_mismatched_reason_is_refused(): void
    {
        $this->submit($this->basket(['lines' => [$this->line('Insulin', 'E', 'VATEX-SA-35')]]))
            ->assertSessionHasErrors(['lines.0.exempt_code' => 'Line 1: VATEX-SA-35 is a reason for tax category Z, not E (BR-KSA-CL-04).']);
    }

    public function test_rates_follow_the_category(): void
    {
        $this->submit($this->basket(['lines' => [
            [...$this->line('Insulin', 'Z', 'VATEX-SA-35'), 'tax_rate' => '15'],
            [...$this->line('Sunscreen', 'S'), 'tax_rate' => '0'],
        ]]))->assertSessionHasErrors([
            'lines.0.tax_rate' => 'Line 1: a line that is zero-rated, exempt or out of scope has a rate of zero (BR-Z-05, BR-E-05).',
            'lines.1.tax_rate' => 'Line 2: a standard-rated line has a rate above zero (BR-S-05).',
        ]);
    }

    /** BR-KSA-49: a citizen's zero rating names the citizen. */
    public function test_citizen_healthcare_needs_a_national_id(): void
    {
        $consult = $this->line('Consultation', 'Z', 'VATEX-SA-HEA');

        $this->submit($this->basket(['lines' => [$consult], 'buyer_id_scheme' => 'IQA', 'buyer_id' => '2123456789']))
            ->assertSessionHasErrors(['buyer_id_scheme' => 'VATEX-SA-HEA is zero-rated only for a citizen: identify the buyer by national ID (BR-KSA-49).']);

        $this->submit($this->basket(['lines' => [$consult], 'invoice_number' => 'CLINIC-1', 'buyer_id_scheme' => 'NAT', 'buyer_id' => '1123456789']))
            ->assertSessionHasNoErrors();
    }

    /**
     * One VAT breakdown per category and rate (XML Implementation Standard
     * v1.2, §8.4), and the reason is stated only there: a second reason in
     * the same category would be lost, so it is refused in both languages.
     */
    public function test_two_reasons_in_one_category_refused(): void
    {
        $this->submit($this->basket(['lines' => [
            $this->line('Exported printer', 'Z', 'VATEX-SA-32'),
            $this->line('Freight to Houston', 'Z', 'VATEX-SA-34-1'),
        ]]))->assertSessionHasErrors(['lines.1.exempt_code' => 'Tax category Z takes one exemption reason per invoice, but this invoice gives VATEX-SA-32, VATEX-SA-34-1: put them on separate invoices (ZATCA XML Implementation Standard §8.4). / '
            .'تأخذ فئة الضريبة Z سبب إعفاء واحداً في الفاتورة، لكن هذه الفاتورة تذكر VATEX-SA-32, VATEX-SA-34-1: أصدِرها في فواتير منفصلة (المعيار التنفيذي لصيغة XML لدى هيئة الزكاة والضريبة والجمارك، البند 8.4).']);
    }

    /**
     * A reason the seller words (VATEX-SA-OOS) is stated once in the
     * breakdown too (BT-120): two different wordings would lose one, so they
     * are refused; the same wording on several lines is taken.
     */
    public function test_two_wordings_of_one_reason_refused(): void
    {
        $disbursement = fn (string $words): array => [...$this->line('Disbursement', 'O', 'VATEX-SA-OOS'), 'exempt_reason' => $words];

        $this->submit($this->basket(['lines' => [$disbursement('Visa fees paid for the buyer'), $disbursement('Court fees paid for the buyer')]]))
            ->assertSessionHasErrors(['lines.1.exempt_code' => 'VATEX-SA-OOS is stated with one wording per invoice, but this invoice words it twice: put them on separate invoices or word them the same (ZATCA XML Implementation Standard §8.4). / '
                .'يُذكر VATEX-SA-OOS بصياغة واحدة في الفاتورة، لكن هذه الفاتورة تصوغه مرتين: أصدِرها في فواتير منفصلة أو وحِّد صياغتها (المعيار التنفيذي لصيغة XML لدى هيئة الزكاة والضريبة والجمارك، البند 8.4).']);

        $this->submit($this->basket(['invoice_number' => 'PHARMA-2', 'lines' => [$disbursement('Fees paid for the buyer'), $disbursement(' Fees paid  for the buyer')]]))
            ->assertSessionHasNoErrors();
    }

    /** The same reason on several lines is one breakdown, and taken. */
    public function test_one_reason_on_several_lines(): void
    {
        $this->submit($this->basket())->assertSessionHasNoErrors();
    }

    /** Out of scope has no official text, so the seller writes the reason. */
    public function test_out_of_scope_needs_words(): void
    {
        $this->submit($this->basket(['lines' => [$this->line('Disbursement', 'O', 'VATEX-SA-OOS')]]))
            ->assertSessionHasErrors(['lines.0.exempt_reason']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basket(array $overrides = []): array
    {
        return [
            '_token' => self::TOKEN,
            'invoice_number' => 'PHARMA-1',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in customer',
            'buyer_name_ar' => 'عميل نقدي',
            'prices_include_vat' => '0',
            'discount_amount' => '10.00',
            'lines' => [
                [...$this->line('Paracetamol 500mg', 'Z', 'VATEX-SA-35'), 'quantity' => '3', 'unit_price' => '12.75'],
                [...$this->line('Insulin pen', 'Z', 'VATEX-SA-35'), 'unit_price' => '189.90'],
                [...$this->line('Sunscreen SPF50', 'S'), 'quantity' => '2', 'unit_price' => '64.50'],
            ],
            ...$overrides,
        ];
    }

    /**
     * A line as the form sends it: the rate the category fills in, and an
     * empty reason in words for the server to fill.
     *
     * @return array<string, string>
     */
    private function line(string $description, string $category, string $code = ''): array
    {
        return [
            'description' => $description,
            'description_ar' => 'صنف',
            'quantity' => '1',
            'unit_price' => '100.00',
            'unit_code' => 'C62',
            'tax_category' => $category,
            'tax_rate' => $category === 'S' ? '15' : '0',
            'exempt_code' => $code,
            'exempt_reason' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submit(array $payload): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($this->member)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/invoices', $payload));
    }
}
