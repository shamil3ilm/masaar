<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSequence;
use App\Domains\Invoice\Services\InvoiceNumbering;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use Throwable;

/**
 * Tills of one company issue at the same time; one till issues in order.
 *
 * ZATCA keeps a counter and a chain per device, so only documents of one
 * device need to wait for each other. Issuance used to hold the organization
 * row, which made every till of a chain of shops wait for every other.
 *
 * A second database connection plays a till that is part-way through issuing:
 * it holds the issuing lock of one device in an open transaction, as an
 * issuance does while it signs. MySQL only - SQLite takes one lock for the
 * whole database, so it cannot show two writers at once.
 *
 * The branch numbers its documents in a sequence of its own. Devices sharing
 * one sequence also wait for each other while the number is taken, which is
 * InvoiceNumbering's to say.
 */
class DeviceConcurrencyTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const HOLDER = 'issuing_holder';

    private Organization $acme;

    private Branch $jeddah;

    private ?Connection $holder = null;

    private int $timeout = 50;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Needs a database with row locks.');
        }

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Retail',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $this->jeddah = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id,
            'name' => 'Jeddah Till',
            'device_serial' => 'EGS-JED-'.uniqid(),
            'industry' => 'Retail',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
        ]));

        $this->onboard(null);
        $this->onboard($this->jeddah->id);

        InvoiceSequence::withoutTenantScope(fn () => InvoiceSequence::create(
            ['pattern' => 'JED-{SEQ}'] + InvoiceNumbering::defaults($this->acme->id, 'invoice', $this->jeddah->id)
        ));

        // A waiting issuance gives up after a second instead of fifty.
        $this->timeout = (int) DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->t;
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        // The held row was never committed, so rolling back leaves nothing.
        if ($this->holder !== null) {
            $this->holder->rollBack();
            DB::purge(self::HOLDER);
            $this->holder = null;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.$this->timeout);
        }

        parent::tearDown();
    }

    /**
     * Head office is part-way through issuing; the branch till does not
     * wait for it, and takes nothing head office's issuance holds - not the
     * organization row either, whose lock would also hold up every insert
     * whose foreign key checks it.
     */
    public function test_another_device_issues_meanwhile(): void
    {
        $this->hold($this->acme->id);
        $draft = $this->draft(branchId: $this->jeddah->id);

        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'for update') || str_contains($query->sql, 'on duplicate key')) {
                $locks[] = $query->sql;
            }
        });

        $invoice = $this->issue($draft);

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame($this->jeddah->id, $invoice->device_id);
        $this->assertSame(1, $invoice->icv);
        $this->assertSame('JED-00001', $invoice->invoice_number);
        $this->assertSame([], array_values(array_filter($locks, fn (string $sql): bool => str_contains($sql, '`organizations`'))));
    }

    /**
     * Head office is part-way through issuing; its next document waits, and
     * leaves nothing behind when it gives up. Once the first finishes it
     * takes the next counter and names the one before it.
     */
    public function test_same_device_waits_its_turn(): void
    {
        $this->hold($this->acme->id);
        $waiting = $this->draft();

        try {
            $this->issue($waiting);
            $this->fail('A second document of one device issued while the first held its chain.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        }

        $stored = Invoice::withoutTenantScope(fn () => $waiting->fresh());
        $this->assertSame(InvoiceStatus::Draft, $stored->status);
        $this->assertNull($stored->icv, 'the waiting document took a counter');

        $this->release();

        $issued = $this->issue($stored);
        $next = $this->issue($this->draft());

        $this->assertSame([1, 2], [$issued->icv, $next->icv]);
        $this->assertNull($issued->previous_invoice_hash, 'the first document named a predecessor');
        $this->assertSame($issued->hash, $next->previous_invoice_hash);
    }

    /**
     * A second connection holding one device's issuing lock, the way an
     * issuance holds it while it signs.
     */
    private function hold(string $device): void
    {
        config(['database.connections.'.self::HOLDER => config('database.connections.'.config('database.default'))]);

        $this->holder = DB::connection(self::HOLDER);
        $this->holder->beginTransaction();
        $this->holder->table('issuing_locks')->upsert(
            [['org_id' => $this->acme->id, 'scope' => $device, 'held_at' => now()]],
            ['org_id', 'scope'],
            ['held_at'],
        );
    }

    private function release(): void
    {
        $this->holder?->rollBack();
    }

    private function onboard(?string $branchId): void
    {
        $keys = $this->selfSignedCredentials();

        app(CredentialStore::class)->put($this->acme->id, $branchId, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'],
            'pcsid' => $keys['certificate'],
            'secret' => 'device-secret',
        ]);
    }

    /**
     * A draft numbered at issuance, as a till's sale is.
     */
    private function draft(?string $branchId = null): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'branch_id' => $branchId,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Widget',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        return $invoice;
    }

    private function issue(Invoice $invoice): Invoice
    {
        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme->fresh());

        return Invoice::withoutTenantScope(fn () => $invoice->fresh());
    }
}
