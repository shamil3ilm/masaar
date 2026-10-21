<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Services\SalesReports;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The period's sales by customer, item, VAT category and month, credit
 * notes taken off and foreign currency converted.
 */
class SalesReportsTest extends TestCase
{
    use BooksFixtures;
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->saudi();
        // Gulf: 1,000 + 500 at 15%, less a 200 credit note: 1,300 (VAT 195).
        $this->sale($this->acme, [[1000, 15]], ['buyer_name' => 'Gulf Supplies', 'item' => 'Desk']);
        $this->sale($this->acme, [[500, 15]], ['buyer_name' => 'Gulf Supplies', 'item' => 'Chair']);
        $this->sale($this->acme, [[200, 15]], ['buyer_name' => 'Gulf Supplies', 'item' => 'Desk', 'document_type' => 'credit_note', 'billing_ref' => 'INV-1']);
        // Noor: USD 100 at 3.75, exported: 375 (VAT 0).
        $this->sale($this->acme, [[100, 0, 'Z', 'VATEX-SA-32']], ['buyer_name' => 'Noor LLC', 'item' => 'Desk', 'currency' => 'USD', 'exchange_rate' => '3.75']);
    }

    public function test_sales_by_customer(): void
    {
        $report = $this->report('customer');

        $this->assertSame([['Gulf Supplies', 3, 1300.0, 195.0, 1495.0], ['Noor LLC', 1, 375.0, 0.0, 375.0]], $this->rows($report));
        $this->assertSame([1675.0, 195.0, 1870.0], [$report['base'], $report['vat'], $report['total']]);
    }

    /** Desk: 1,000 - 200 + 375 = 1,175 over three documents, quantity 1 - 1 + 1. */
    public function test_sales_by_item(): void
    {
        $report = $this->report('item');

        $this->assertSame(['Desk', 3, 1175.0, 120.0, 1295.0], $this->rows($report)[0]);
        $this->assertSame(1.0, $report['rows'][0]['quantity']);
    }

    public function test_sales_by_vat_category(): void
    {
        $this->assertSame([['S', 3, 1300.0, 195.0, 1495.0], ['Z VATEX-SA-32', 1, 375.0, 0.0, 375.0]], $this->rows($this->report('category')));
    }

    public function test_sales_by_month(): void
    {
        $this->assertSame([[now()->format('Y-m'), 4, 1675.0, 195.0, 1870.0]], $this->rows($this->report('month')));
    }

    /** @return array<string, mixed> */
    private function report(string $group): array
    {
        return app(SalesReports::class)->by($this->acme, $group, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());
    }

    /** @return list<list<mixed>> */
    private function rows(array $report): array
    {
        return array_map(fn (array $row): array => [$row['label'], $row['documents'], $row['base'], $row['vat'], $row['total']], $report['rows']);
    }
}
