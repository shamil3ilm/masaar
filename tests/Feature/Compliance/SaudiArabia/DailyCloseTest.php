<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\DailyClose;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\VatSummary;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A till's close of the day, per device and per branch.
 *
 * Head office issues on its own device, and so does the lobby kiosk, a
 * branch with no certificate of its own. The Jeddah shop has its own
 * certificate, so it is a device of its own with its own counter.
 */
class DailyCloseTest extends TestCase
{
    use RefreshDatabase;

    /** 01:30 on the 16th in Riyadh: the day's, though UTC still says the 15th. */
    private const EARLY = '2026-10-15 22:30:00';

    private Organization $acme;

    private Branch $jeddah;

    private Branch $lobby;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Retail', 'country' => 'SA']);
        $this->jeddah = $this->branch('Jeddah shop');
        $this->lobby = $this->branch('Lobby kiosk');

        $this->document('HQ-1', $this->acme->id, null, 1, self::EARLY, '100.00');
        $this->document('LOB-1', $this->acme->id, $this->lobby->id, 2, '2026-10-16 09:00:00', '200.00');
        $this->document('HQ-CN-1', $this->acme->id, null, 3, '2026-10-16 10:00:00', '50.00', 'credit_note');
        $this->document('JED-1', $this->jeddah->id, $this->jeddah->id, 7, '2026-10-16 11:00:00', '300.00');
        $this->document('JED-2', $this->jeddah->id, $this->jeddah->id, 9, '2026-10-16 12:00:00', '0.00', zeroRated: true);

        // The day before, and the day after, in Riyadh.
        $this->document('HQ-0', $this->acme->id, null, 0, '2026-10-15 20:00:00', '999.00');
        $this->document('HQ-9', $this->acme->id, null, 4, '2026-10-16 21:30:00', '999.00');
    }

    public function test_each_device_closes_its_own(): void
    {
        $devices = collect($this->close()['devices'])->keyBy('id');

        $head = $devices[$this->acme->id];
        $this->assertSame(__('Head office'), $head['name']);
        $this->assertSame([3, 2, 1], [$head['documents'], $head['invoices'], $head['credit_notes']]);
        $this->assertSame(['HQ-1', 1, 'HQ-CN-1', 3], [$head['first']['number'], $head['first']['counter'], $head['last']['number'], $head['last']['counter']]);
        $this->assertTrue($head['contiguous']);
        $this->assertSame(['net' => 300.0, 'vat' => 45.0, 'total' => 345.0], $head['sales']);
        $this->assertSame(['net' => 50.0, 'vat' => 7.5, 'total' => 57.5], $head['returns']);
        $this->assertSame(287.5, $head['net']);
        $this->assertSame([['rate' => '15', 'net' => 250.0, 'vat' => 37.5]], $head['rates']);

        $shop = $devices[$this->jeddah->id];
        $this->assertSame(__('Till :number · :name', ['number' => 1, 'name' => 'Jeddah shop']), $shop['name']);
        $this->assertSame([7, 9], [$shop['first']['counter'], $shop['last']['counter']]);
        $this->assertFalse($shop['contiguous'], 'counter 8 is missing and the close did not say so');
        $this->assertSame(['0 (Z)', '15'], array_column($shop['rates'], 'rate'));
    }

    /**
     * The close reconciles with the documents: devices and branches add up
     * to the same figures, to the VAT summary for the day, and to the
     * invoices' own totals less the credit notes'.
     */
    public function test_the_close_reconciles_exactly(): void
    {
        $this->document('R-1', $this->acme->id, null, 10, '2026-10-20 06:00:00', '123.45');
        $this->document('R-2', $this->acme->id, $this->lobby->id, 11, '2026-10-20 07:00:00', '0.10');
        $this->document('R-3', $this->jeddah->id, $this->jeddah->id, 10, '2026-10-20 08:00:00', '999.99');
        $this->document('R-4', $this->jeddah->id, $this->jeddah->id, 11, '2026-10-20 09:00:00', '77.70', zeroRated: true);
        $this->document('R-CN', $this->acme->id, null, 12, '2026-10-20 10:00:00', '23.45', 'credit_note');

        $close = app(DailyClose::class)->for($this->acme->id, CarbonImmutable::parse('2026-10-20'));
        $sum = fn (array $groups, callable $of): float => round(array_sum(array_map($of, $groups)), 2);
        $net = fn (array $group): float => $group['sales']['net'] - $group['returns']['net'];
        $vat = fn (array $group): float => $group['sales']['vat'] - $group['returns']['vat'];

        $summary = app(VatSummary::class)->for($this->acme->id, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-20'));
        $documents = Invoice::withoutTenantScope(fn () => Invoice::query()->where('invoice_number', 'like', 'R-%')->get());
        $filed = round($documents->sum(fn (Invoice $i): float => ($i->document_type->value === 'credit_note' ? -1 : 1) * (float) $i->total), 2);

        $this->assertSame($summary['net_base'], $sum($close['devices'], $net));
        $this->assertSame($summary['net_vat'], $sum($close['devices'], $vat));
        $this->assertSame($sum($close['devices'], $net), $sum($close['branches'], $net));
        $this->assertSame($sum($close['devices'], $vat), $sum($close['branches'], $vat));
        $this->assertSame($filed, $sum($close['devices'], fn (array $group): float => $group['net']));
        $this->assertSame($filed, $sum($close['branches'], fn (array $group): float => $group['net']));
        $this->assertSame(5, array_sum(array_column($close['devices'], 'documents')));
    }

    /**
     * Two tills of one branch are two devices: each closes with its own
     * counters, and the branch adds them up.
     */
    public function test_tills_of_a_branch_close_apart(): void
    {
        $lane = app(Tills::class)->add($this->jeddah, 'Express lane');
        $this->document('EXP-1', $lane->id, $this->jeddah->id, 1, '2026-10-16 13:00:00', '100.00');

        $close = $this->close();
        $devices = collect($close['devices'])->keyBy('id');

        $this->assertSame('Express lane · Jeddah shop', $devices[$lane->id]['name']);
        $this->assertSame([1, 1], [$devices[$lane->id]['first']['counter'], $devices[$lane->id]['documents']]);
        $this->assertSame(2, $devices[$this->jeddah->id]['documents']);
        $this->assertSame(3, collect($close['branches'])->keyBy('id')[$this->jeddah->id]['documents']);
    }

    public function test_each_branch_closes_its_own(): void
    {
        $branches = collect($this->close()['branches'])->keyBy('name');

        $this->assertSame([__('Head office'), 'Jeddah shop', 'Lobby kiosk'], $branches->keys()->all());
        $this->assertSame(2, $branches[__('Head office')]['documents']);
        $this->assertSame(['LOB-1', 1, 230.0], [$branches['Lobby kiosk']['first']['number'], $branches['Lobby kiosk']['documents'], $branches['Lobby kiosk']['net']]);
    }

    public function test_the_page_and_its_file(): void
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'viewer', 'status' => 'active']);
        $portal = $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);

        $portal->get(route('portal.reports'))->assertOk()->assertSee(route('portal.reports.close'), false);
        $portal->get(route('portal.reports.close', ['day' => '2026-10-16']))
            ->assertOk()
            ->assertSee('Jeddah shop')
            ->assertSee('JED-2')
            ->assertSee(__('The counters this day have a gap.'));

        $csv = $portal->get(route('portal.reports.close.csv', ['day' => '2026-10-16']))->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Device,"Head office",3,2,1,0,0,HQ-1,1,HQ-CN-1,3,yes,300.00,45.00,345.00,50.00,7.50,57.50,287.50,', $csv);
        $this->assertStringContainsString('Branch,"Lobby kiosk",1,1,0,0,0,LOB-1,2,LOB-1,2,yes,200.00,30.00,230.00,0.00,0.00,0.00,230.00,', $csv);
        $this->assertStringContainsString('Device,"'.__('Till :number · :name', ['number' => 1, 'name' => 'Jeddah shop']).'","0 (Z)",0.00,0.00', $csv);
    }

    /**
     * A UAE organization has no device counters, so no close.
     */
    public function test_no_close_without_device_counters(): void
    {
        config(['peppol.countries.AE.enabled' => true]);

        $gulf = Organization::create(['name' => 'Gulf Trading LLC', 'country' => 'AE', 'status' => 'active']);
        $user = User::factory()->create();
        $user->organizations()->attach($gulf->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($user)->withSession(['portal_org_id' => $gulf->id])
            ->get(route('portal.reports.close'))
            ->assertNotFound();
    }

    /**
     * @return array{day: string, devices: list<array<string, mixed>>, branches: list<array<string, mixed>>}
     */
    private function close(): array
    {
        return app(DailyClose::class)->for($this->acme->id, CarbonImmutable::parse('2026-10-16'));
    }

    private function branch(string $name): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id,
            'name' => $name,
            'device_serial' => 'EGS-'.uniqid(),
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
        ]));
    }

    /**
     * An issued document, its one line at 15% unless zero-rated. $issuedAt is UTC.
     */
    private function document(string $number, string $device, ?string $branch, int $counter, string $issuedAt, string $net, string $type = 'invoice', bool $zeroRated = false): void
    {
        $vat = $zeroRated ? '0.00' : number_format((float) $net * 0.15, 2, '.', '');
        $total = number_format((float) $net + (float) $vat, 2, '.', '');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'branch_id' => $branch,
            'invoice_number' => $number,
            'type' => 'simplified',
            'document_type' => $type,
            'billing_ref' => $type === 'credit_note' ? 'HQ-1' : null,
            'status' => 'draft',
            'issue_date' => substr($issuedAt, 0, 10),
            'currency' => 'SAR',
            'subtotal' => $net,
            'tax_amount' => $vat,
            'total' => $total,
        ]));

        $invoice->lines()->create([
            'description' => 'Item',
            'quantity' => '1.000',
            'unit_price' => $net,
            'net_amount' => $net,
            'tax_rate' => $zeroRated ? '0.00' : '15.00',
            'tax_category' => $zeroRated ? 'Z' : 'S',
            'exempt_code' => $zeroRated ? 'VATEX-SA-32' : null,
            'tax_amount' => $vat,
            'line_total' => $total,
        ]);

        // Issued, as issuance leaves it: device, counter, hash, moment.
        $invoice->forceFill([
            'status' => 'issued',
            'device_id' => $device,
            'icv' => $counter,
            'hash' => 'hash-'.$number,
            'issued_at' => $issuedAt,
        ])->save();
    }
}
