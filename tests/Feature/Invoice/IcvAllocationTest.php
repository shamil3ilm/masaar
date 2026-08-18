<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ICV is the ZATCA invoice counter. It must be strictly sequential per
 * taxpayer, and the previous-invoice-hash chain is built on it, so a
 * duplicate or a gap is a compliance failure rather than a cosmetic one.
 */
class IcvAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_icv_is_one(): void
    {
        $invoice = $this->makeInvoice($this->makeOrganization()->id, 'INV-1');

        $this->assertSame(1, $invoice->icv);
    }

    public function test_icv_increments(): void
    {
        $organizationId = $this->makeOrganization()->id;

        $icvs = collect(range(1, 5))
            ->map(fn (int $n) => $this->makeInvoice($organizationId, "INV-{$n}")->icv)
            ->all();

        $this->assertSame([1, 2, 3, 4, 5], $icvs);
    }

    /**
     * The counter is per taxpayer, so one organization's invoices must not
     * advance another's.
     */
    public function test_counter_is_per_org(): void
    {
        $first = $this->makeOrganization('First Co')->id;
        $second = $this->makeOrganization('Second Co')->id;

        $this->makeInvoice($first, 'A-1');
        $this->makeInvoice($first, 'A-2');
        $freshOrgInvoice = $this->makeInvoice($second, 'B-1');

        $this->assertSame(1, $freshOrgInvoice->icv);
        $this->assertSame(3, $this->makeInvoice($first, 'A-3')->icv);
    }

    public function test_explicit_icv_kept(): void
    {
        $invoice = $this->makeInvoice($this->makeOrganization()->id, 'INV-9', ['icv' => 42]);

        $this->assertSame(42, $invoice->icv);
    }

    /**
     * The database constraint is the backstop when two writers race past the
     * application lock. Without it a collision would silently break the chain
     * instead of failing the insert.
     */
    public function test_duplicate_icv_rejected(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite'
            && ! $this->uniqueIndexExists('invoices', 'invoices_org_icv_unique')) {
            $this->markTestSkipped('Unique index not present on this connection.');
        }

        $organizationId = $this->makeOrganization()->id;
        $this->makeInvoice($organizationId, 'INV-1');

        $this->expectException(QueryException::class);

        $this->makeInvoice($organizationId, 'INV-2', ['icv' => 1]);
    }

    private function uniqueIndexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $i) => $i['name'] === $index);
    }

    private function makeOrganization(string $name = 'Acme'): Organization
    {
        return Organization::create(['name' => $name, 'country' => 'SA']);
    }

    private function makeInvoice(string $organizationId, string $number, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'organization_id' => $organizationId,
            'invoice_number' => $number,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ], $overrides));
    }
}
