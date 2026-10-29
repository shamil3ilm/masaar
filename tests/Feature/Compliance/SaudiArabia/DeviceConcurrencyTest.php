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
use App\Domains\Organization\Services\Tills;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 * Each till numbers its documents from a series of its own by default
 * (InvoiceNumbering), so two tills do not wait for each other for a number
 * either. An organization that chooses one shared series has its tills take
 * turns for it.
 *
 * A branch may have several tills, each a unit of its own (egs_units): they
 * do not wait for each other either.
 */
class DeviceConcurrencyTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const HOLDER = 'issuing_holder';

    private Organization $acme;

    private Branch $jeddah;

    private Branch $dammam;

    private ?Connection $holder = null;

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

        $this->jeddah = Branch::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'name' => 'Jeddah Till',
            'device_serial' => 'EGS-JED-'.uniqid(),
            'industry' => 'Retail',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
        ]);

        $this->dammam = Branch::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'name' => 'Dammam Till',
            'device_serial' => 'EGS-DMM-'.uniqid(),
            'industry' => 'Retail',
            'street' => 'King Saud Street',
            'building_number' => '5678',
            'district' => 'Al Faisaliyah',
            'city' => 'Dammam',
            'postal_code' => '32272',
        ]);

        $this->onboard(null);
        $this->onboard($this->jeddah->id);
        $this->onboard($this->dammam->id);

        // A waiting issuance gives up after a second (IssuingLifecycle).
        config(['database.issuing_lock_wait' => 1]);
    }

    protected function tearDown(): void
    {
        // The held row was never committed, so rolling back leaves nothing.
        if ($this->holder !== null) {
            $this->holder->rollBack();
            DB::purge(self::HOLDER);
            $this->holder = null;
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
        $this->assertSame('INV-T01-'.$this->year().'-00001', $invoice->invoice_number);
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
            $this->assertBusy($e);
        }

        $stored = $waiting->fresh();
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
     * Another till is part-way through issuing and has taken its next
     * number: its chain and its numbering series are held. This till takes
     * neither - its own series is a row of its own - and issues at once.
     */
    public function test_two_tills_never_wait_to_number(): void
    {
        $this->hold($this->dammam->id);
        $this->holdSeries($this->dammam->id);
        $this->holdSeries(InvoiceSequence::ORGANIZATION);

        $invoice = $this->issue($this->draft(branchId: $this->jeddah->id));

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('INV-T01-'.$this->year().'-00001', $invoice->invoice_number);
        $this->assertSame(1, $invoice->icv);
    }

    /**
     * Two tills of one branch: the first is part-way through issuing, with
     * its chain and its series held. The second is a unit of its own - its
     * own lock, counter and series - and issues at once.
     */
    public function test_tills_of_a_branch_never_wait(): void
    {
        $second = app(Tills::class)->add($this->jeddah);
        $this->onboard($second->id);

        $this->hold($this->jeddah->id);
        $this->holdSeries($this->jeddah->id);

        $draft = $this->draft(branchId: $this->jeddah->id);
        $draft->forceFill(['device_id' => $second->id])->save();
        $invoice = $this->issue($draft);

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame($second->id, $invoice->device_id);
        $this->assertSame(1, $invoice->icv);
        $this->assertSame('INV-T03-'.$this->year().'-00001', $invoice->invoice_number);
    }

    /**
     * With one series for the whole organization, a till waits while head
     * office has the next number, though their chains are apart - which is
     * why each till numbering from its own series is the default.
     */
    public function test_a_shared_series_makes_tills_wait(): void
    {
        $this->acme->forceFill(['shared_numbering' => true])->save();
        $this->holdSeries(InvoiceSequence::ORGANIZATION);
        $waiting = $this->draft(branchId: $this->jeddah->id);

        try {
            $this->issue($waiting);
            $this->fail('A till took a number from the shared series while head office held it.');
        } catch (Throwable $e) {
            $this->assertBusy($e);
        }

        $this->assertSame(InvoiceStatus::Draft, $waiting->fresh()->status);
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

    /**
     * The same connection holding a numbering series, the way an issuance
     * holds it from taking the number until it commits: here by creating it,
     * uncommitted, which locks its row as taking a number does.
     *
     * The organization and its branches exist only in this test's own open
     * transaction, so the holder writes without checking foreign keys.
     */
    private function holdSeries(string $scope): void
    {
        if ($this->holder === null) {
            config(['database.connections.'.self::HOLDER => config('database.connections.'.config('database.default'))]);
            $this->holder = DB::connection(self::HOLDER);
            $this->holder->beginTransaction();
        }

        $this->holder->statement('SET FOREIGN_KEY_CHECKS = 0');
        $this->holder->table('invoice_sequences')->insert([
            'id' => (string) Str::uuid(),
            ...($scope === InvoiceSequence::ORGANIZATION
                ? InvoiceNumbering::defaults($this->acme->id, 'invoice')
                : app(InvoiceNumbering::class)->tillDefaults($this->acme->id, 'invoice', $scope)),
            'next_number' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function year(): string
    {
        return app(InvoiceNumbering::class)->now($this->acme->id)->format('Y');
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
        $invoice = Invoice::acrossTenants()->create([
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
        ]);

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

    /**
     * Waited out, it is refused as busy - retryable, 503 on the API - with
     * the database's lock wait as its cause, not passed on as a crash.
     */
    private function assertBusy(Throwable $e): void
    {
        $this->assertInstanceOf(ComplianceException::class, $e, $e->getMessage());
        $this->assertSame(ErrorCode::SYS_BUSY, $e->getErrorCode());
        $this->assertStringContainsString('Lock wait timeout', (string) $e->getPrevious()?->getMessage());
    }

    private function issue(Invoice $invoice): Invoice
    {
        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme->fresh());

        return $invoice->fresh();
    }
}
