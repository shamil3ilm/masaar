<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A line carrying an exemption code must not also be standard-rated.
 *
 * ZATCA reads the tax category and the exemption reason together: VATEX-SA-HEA
 * on a line taxed at 15% is a contradiction, and the authority rejects the
 * document rather than picking one.
 *
 * The request filled every line with tax_category "S" and tax_rate 15 before
 * validation, so a caller who sent an exemption code and left the category to
 * the platform got exactly that contradiction. DocumentBuilder knows how to
 * derive the category from the code — the defaults meant it was never asked,
 * because it only runs when the category is absent.
 *
 * The validator checked the other direction, refusing a Z, E or O line with no
 * exemption code. Only the direction that was checked could go wrong.
 */
class ExemptLineTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $user = User::factory()->create(['email' => 'biller@example.test']);
        $user->organizations()->attach($organization->id, [
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'biller@example.test',
            'password' => 'password',
        ])->json('data.token.access_token');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function exemptions(): array
    {
        return [
            'healthcare is zero-rated' => ['VATEX-SA-HEA', 'Z'],
            'education is zero-rated' => ['VATEX-SA-EDU', 'Z'],
            // VATEX-SA-OOS-1 and -OOS-2 used to stand here for services to
            // non-GCC customers and exported goods. Neither is a code ZATCA
            // recognises — BR-KSA-CL-04 lists VATEX-SA-OOS alone — so the
            // request no longer accepts them and an invoice carrying one could
            // never have been filed. Export is billed under VATEX-SA-34-3,
            // which is zero-rated rather than out of scope.
            'out of scope' => ['VATEX-SA-OOS', 'O'],
            // These three said 'E' under names that said otherwise, and two
            // named the wrong code: VATEX-SA-33 is qualifying metals, not
            // financial services, and VATEX-SA-36 is local passenger
            // transport, not metals. The labels came from
            // TaxCategory::fromExemptionCode, a mapper with no callers whose
            // comments were shifted against the real list. BR-KSA-CL-04 puts
            // all three in 'Z'; the comment above already said as much about
            // VATEX-SA-34-3 while the expectation below it said 'E'.
            'exported goods are zero-rated' => ['VATEX-SA-34-3', 'Z'],
            'qualifying metals are zero-rated' => ['VATEX-SA-33', 'Z'],
            'local transport is zero-rated' => ['VATEX-SA-36', 'Z'],

            // The 'E' branch, with the code that is actually in it.
            'financial services are exempt' => ['VATEX-SA-29', 'E'],
            'life insurance is exempt' => ['VATEX-SA-29-7', 'E'],
        ];
    }

    #[DataProvider('exemptions')]
    public function test_the_code_decides_the_category(string $code, string $category): void
    {
        $line = $this->create($code)->lines->first();

        $this->assertSame(
            $category,
            $line->tax_category,
            "A line exempt under {$code} was filed as {$line->tax_category}."
        );
    }

    /**
     * An exempt line is not taxed, so the default rate must not survive either.
     */
    public function test_an_exempt_line_is_not_taxed(): void
    {
        $line = $this->create('VATEX-SA-HEA')->lines->first();

        $this->assertSame(0.0, (float) $line->tax_rate);
        $this->assertSame(0.0, (float) $line->tax_amount);
    }

    /**
     * A caller who names the category keeps it. The platform fills a gap; it
     * does not overrule.
     */
    public function test_a_stated_category_is_kept(): void
    {
        $line = $this->create('VATEX-SA-29', category: 'E')->lines->first();

        $this->assertSame('E', $line->tax_category);
    }

    /**
     * Nor does it accept a contradiction: VATEX-SA-HEA is a zero-rating
     * reason, so a line calling itself exempt under it is refused with the
     * rule rather than filed as ZATCA would warn about it (BR-KSA-CL-04).
     */
    public function test_a_mismatched_category_is_refused(): void
    {
        $details = $this->withToken($this->token)
            ->postJson('/api/invoices', $this->payload($this->line('VATEX-SA-HEA', 'E'), 'VATEX-SA-HEA'))
            ->assertStatus(422)
            ->json('error.details');

        $this->assertSame('Line 1: VATEX-SA-HEA is a reason for tax category Z, not E (BR-KSA-CL-04).', $details['lines.0.exempt_code'][0] ?? null);
    }

    /**
     * The ordinary line is untouched: no code, standard rate.
     */
    public function test_a_plain_line_stays_standard(): void
    {
        $line = $this->create(null)->lines->first();

        $this->assertSame('S', $line->tax_category);
        $this->assertSame(15.0, (float) $line->tax_rate);
    }

    private function create(?string $code, ?string $category = null): Invoice
    {
        $id = $this->withToken($this->token)
            ->postJson('/api/invoices', $this->payload($this->line($code, $category), $code))
            ->assertSuccessful()
            ->json('data.invoice.id');

        return Invoice::withoutTenantScope(fn () => Invoice::with('lines')->findOrFail($id));
    }

    /**
     * @return array<string, mixed>
     */
    private function line(?string $code, ?string $category = null): array
    {
        $line = [
            'description' => 'Item',
            'description_ar' => 'صنف',
            'quantity' => 1,
            'unit_price' => 100,
        ];

        if ($code !== null) {
            $line['exempt_code'] = $code;
            $line['exempt_reason'] = 'Exempt under '.$code;
        }

        if ($category !== null) {
            $line['tax_category'] = $category;
        }

        return $line;
    }

    /**
     * Healthcare and education to a citizen name the buyer by national ID
     * (BR-KSA-49).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function payload(array $line, ?string $code): array
    {
        return [
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer',
            'buyer_name_ar' => 'المشتري',
            ...(in_array($code, ['VATEX-SA-HEA', 'VATEX-SA-EDU'], true) ? ['buyer_id' => '1234567890', 'buyer_id_scheme' => 'NAT'] : []),
            'lines' => [$line],
        ];
    }
}
