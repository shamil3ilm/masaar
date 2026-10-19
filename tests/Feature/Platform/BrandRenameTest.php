<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Mail\OrganizationReviewedMail;
use App\Domains\Organization\Models\Organization;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * A rename is config/brand.php and nothing else: with the brand set to
 * another name, the home page in both languages, the portal, an email, a
 * PDF and the API's description all say the new one, and none the old.
 */
class BrandRenameTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{en: string, ar: string} the shipped names, before the rename */
    private array $old;

    protected function setUp(): void
    {
        parent::setUp();

        $this->old = ['en' => Brand::name('en'), 'ar' => Brand::name('ar')];

        config(['brand.name' => 'Nibras', 'brand.name_ar' => 'نبراس']);
    }

    public function test_the_home_page_follows(): void
    {
        $this->withSession(['locale' => 'en'])->get('/')->assertOk()
            ->assertSee('Nibras')
            ->assertDontSee($this->old['en']);

        $this->withSession(['locale' => 'ar'])->get('/')->assertOk()
            ->assertSee('نبراس')
            ->assertDontSee($this->old['en'])
            ->assertDontSee($this->old['ar'].' ', false);
    }

    public function test_the_portal_follows(): void
    {
        [$organization, $member] = $this->member();

        $this->actingAs($member)->withSession(['portal_org_id' => $organization->id])
            ->get(route('portal.dashboard'))->assertOk()
            ->assertSee('Powered by Nibras')
            ->assertDontSee($this->old['en']);
    }

    public function test_an_email_follows(): void
    {
        $html = (new OrganizationReviewedMail('Acme Trading', true, null, 'https://example.test/portal'))->render();

        $this->assertStringContainsString('Nibras', $html);
        $this->assertStringNotContainsString($this->old['en'], $html);
    }

    public function test_a_pdf_follows(): void
    {
        [$organization] = $this->member();

        $pdf = app(InvoicePdf::class)->render($this->invoice($organization), $organization);

        $this->assertStringContainsString('Nibras', $pdf);
        $this->assertStringNotContainsString($this->old['en'], $pdf);
    }

    public function test_the_api_title_follows(): void
    {
        Artisan::call('platform:openapi', ['--print' => true]);

        $this->assertStringContainsString("title: 'Nibras Compliance API'", Artisan::output());
    }

    /**
     * @return array{Organization, User}
     */
    private function member(): array
    {
        $organization = Organization::create([
            'name' => 'Acme Trading',
            'name_ar' => 'شركة أكمي للتجارة',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);
        $member = User::factory()->create();
        $member->organizations()->attach($organization->id, ['role' => 'member', 'status' => 'active']);

        return [$organization, $member];
    }

    /** An issued simplified invoice, as InvoicePdfTest makes one. */
    private function invoice(Organization $organization): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $organization->id,
            'invoice_number' => 'ACME-1',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting hour',
            'description_ar' => 'ساعة استشارية',
            'quantity' => '1',
            'unit_code' => 'HUR',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        $invoice->forceFill([
            'status' => 'issued',
            'buyer_name' => 'Gulf Supplies',
            'icv' => 1,
            'hash' => base64_encode(hash('sha256', 'acme', true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='),
            'signed_xml' => '<?xml version="1.0" encoding="UTF-8"?><Invoice>signed</Invoice>',
        ])->save();

        return $invoice->fresh(['lines']);
    }
}
