<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Mail\TillRetiredMail;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\UnitRetirement;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\Services\Tills;
use DomainException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A till is retired only once nothing it signed is left to file - nothing
 * queued offline, waiting for ZATCA or overdue (UnfiledDocuments, as for a
 * renewal) - and a retired till never issues again. Until then it is
 * retiring: it issues nothing new and still files what it signed.
 */
class TillRetirementTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    private Branch $olaya;

    private EgsUnit $second;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Pharmacy',
            'country' => 'SA',
            'status' => 'active',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $this->olaya = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id,
            'name' => 'Olaya',
            'device_serial' => 'EGS-OLAYA',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'onboarding_status' => 'active',
        ]));

        $this->second = app(Tills::class)->add($this->olaya);
        $this->second->forceFill(['onboarding_status' => 'active'])->save();

        foreach ([null, $this->olaya->id, $this->second->id] as $unit) {
            $keys = $this->selfSignedCredentials();
            app(CredentialStore::class)->put($this->acme->id, $unit, CredentialStore::PCSID, [
                'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'secret',
            ]);
        }
    }

    public function test_an_idle_till_retires_at_once(): void
    {
        $unit = $this->retire();

        $this->assertSame(EgsUnit::RETIRED, $unit->status);
        $this->assertNotNull($unit->retired_at);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'zatca.unit.retired')->exists());

        try {
            $this->issue($this->second->id);
            $this->fail('A retired till issued a document.');
        } catch (FatooraException) {
            // Refused before a counter is spent.
        }
    }

    public function test_unfiled_documents_keep_it_retiring(): void
    {
        $waiting = $this->issue($this->second->id);

        $unit = $this->retire();

        $this->assertSame(EgsUnit::RETIRING, $unit->status);
        $this->assertSame([$waiting->invoice_number], app(UnitRetirement::class)->waiting($unit)->pluck('invoice_number')->all());

        // It issues nothing new, and still files what it signed.
        $this->expectExceptionThrown(fn () => $this->issue($this->second->id));
        $this->assertTrue($unit->files());

        // Once ZATCA has it, asking again retires the till.
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($waiting->id)->update(['status' => 'reported']));

        $this->assertSame(EgsUnit::RETIRED, $this->retire()->status);
    }

    /**
     * Nobody has to ask again: the daily run retires every retiring till
     * whose documents are all filed, records it in the organization's audit
     * log as done for the person who asked, and tells its administrators.
     */
    public function test_retiring_tills_finish_on_their_own(): void
    {
        Mail::fake();
        $admin = User::factory()->create();
        $admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $waiting = $this->issue($this->second->id);
        $this->actingAs($admin);
        $this->assertSame(EgsUnit::RETIRING, $this->retire()->status);
        auth()->logout();

        // Not yet: what it signed is still waiting for ZATCA.
        $this->artisan('fatoora:finish-retirements')->assertSuccessful();
        $this->assertSame(EgsUnit::RETIRING, $this->second->fresh()->status);
        Mail::assertNothingSent();

        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($waiting->id)->update(['status' => 'reported']));
        $this->artisan('fatoora:finish-retirements')->assertSuccessful();

        $unit = $this->second->fresh();
        $this->assertSame([EgsUnit::RETIRED, (string) $admin->id], [$unit->status, (string) $unit->retired_by]);
        $this->assertNotNull($unit->retired_at);
        $log = DB::table('audit_logs')->where('action', 'zatca.unit.retired')->sole();
        $this->assertSame([(string) $this->acme->id, (string) $this->second->id], [(string) $log->org_id, json_decode((string) $log->metadata, true)['device_id']]);
        Mail::assertSent(TillRetiredMail::class, fn (TillRetiredMail $mail): bool => $mail->hasTo($admin->email) && str_contains($mail->render(), $mail->tillName));
    }

    /**
     * Outside any request there is no tenant of the moment: each audit row
     * still names the till's organization, the one asking to retire it and
     * the one the command writes on finishing it.
     */
    public function test_audit_rows_name_the_organization(): void
    {
        $waiting = $this->issue($this->second->id);
        $this->assertNull(app(TenantResolver::class)->getOrganizationId());
        $this->retire();

        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($waiting->id)->update(['status' => 'reported']));
        $this->artisan('fatoora:finish-retirements')->assertSuccessful();

        $rows = DB::table('audit_logs')->whereIn('action', ['zatca.unit.retiring', 'zatca.unit.retired'])->orderBy('action')->get(['action', 'org_id']);
        $this->assertSame(
            [['zatca.unit.retired', (string) $this->acme->id], ['zatca.unit.retiring', (string) $this->acme->id]],
            $rows->map(fn ($row): array => [$row->action, (string) $row->org_id])->all(),
        );
    }

    public function test_the_retirements_run_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'fatoora:finish-retirements'));

        $this->assertNotNull($event);
        $this->assertSame('0 5 * * *', $event->expression);
    }

    public function test_head_office_is_never_retired(): void
    {
        $this->expectException(DomainException::class);

        app(UnitRetirement::class)->retire($this->acme, app(Tills::class)->headOffice($this->acme->id));
    }

    public function test_the_portal_retires_a_till(): void
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id])
            ->post(route('portal.zatca.tills.retire', $this->second->id))
            ->assertSessionHas('success');

        $this->assertSame(EgsUnit::RETIRED, $this->second->fresh()->status);
    }

    public function test_removing_a_branch_retires_its_tills(): void
    {
        $empty = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id, 'name' => 'Closed', 'device_serial' => 'EGS-CLOSED',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]));

        app(BranchService::class)->delete($empty);

        $this->assertSame(EgsUnit::RETIRED, EgsUnit::withoutTenantScope(fn () => EgsUnit::query()->find($empty->id))->status);
    }

    private function retire(): EgsUnit
    {
        return app(UnitRetirement::class)->retire($this->acme, $this->second->fresh());
    }

    private function expectExceptionThrown(callable $callback): void
    {
        try {
            $callback();
            $this->fail('A retiring till issued a new document.');
        } catch (FatooraException) {
            $this->addToAssertionCount(1);
        }
    }

    private function issue(string $tillId): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'branch_id' => $this->olaya->id,
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
        $invoice->forceFill(['device_id' => $tillId])->save();
        $invoice->lines()->create([
            'description' => 'Bread', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme->fresh());

        return Invoice::withoutTenantScope(fn () => $invoice->fresh());
    }
}
