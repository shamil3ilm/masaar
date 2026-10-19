<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\PrintPreferences;
use App\Domains\Organization\Services\Tills;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each till has its own paper and receipt printer, over its branch's: the
 * express lane prints on an 80 mm roll while the pharmacy counter of the
 * same branch prints A4. A person's own choice still comes first.
 */
class TillPrintingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Branch $olaya;

    private EgsUnit $lane;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => 'active']);
        $this->olaya = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id, 'name' => 'Olaya', 'device_serial' => 'EGS-OLAYA',
            'street' => 'King Road', 'building_number' => '1234', 'district' => 'Centre', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]));
        $this->lane = app(Tills::class)->add($this->olaya, 'Express lane');
    }

    public function test_a_till_prints_on_its_own(): void
    {
        $printing = app(PrintPreferences::class);
        $printing->setBranchPaper($this->acme->id, $this->olaya->id, PaperSize::A4);

        $this->asAdmin()->post(route('portal.zatca.tills.printing', $this->lane->id), ['paper' => PaperSize::Roll80->value, 'dpi' => 300, 'dots' => 640])
            ->assertSessionHas('success');

        // The lane's own; the branch's first till still prints as the branch.
        $this->assertSame(PaperSize::Roll80, $printing->paper($this->acme, $this->olaya->id, null, null, $this->lane->id));
        $this->assertSame(PaperSize::A4, $printing->paper($this->acme, $this->olaya->id, null, null, $this->olaya->id));
        $this->assertSame(['dpi' => 300, 'dots' => 640], $printing->receiptPrinter($this->acme->id, $this->olaya->id, $this->lane->id));
        $this->assertSame(['dpi' => null, 'dots' => null], $printing->receiptPrinter($this->acme->id, $this->olaya->id, $this->olaya->id));
    }

    public function test_a_persons_own_paper_comes_first(): void
    {
        $printing = app(PrintPreferences::class);
        $printing->setTillPrinting($this->acme->id, $this->lane->id, PaperSize::Roll80, null, null);
        $user = User::factory()->create();
        $printing->setUserPaper($this->acme->id, (string) $user->id, PaperSize::A5);

        $this->assertSame(PaperSize::A5, $printing->paper($this->acme, $this->olaya->id, (string) $user->id, null, $this->lane->id));
    }

    public function test_head_office_has_no_till_printer(): void
    {
        $printing = app(PrintPreferences::class);
        $printing->setTillPrinting($this->acme->id, $this->acme->id, PaperSize::Roll80, null, null);

        $this->assertSame(PaperSize::A4, $printing->paper($this->acme, null, null, null, $this->acme->id));
    }

    private function asAdmin(): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }
}
