<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\PrintPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Which paper an invoice prints on: the one chosen on the print screen, the
 * person's own, the till's, the organization's, or A4.
 */
class PrintPreferencesTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private User $cashier;

    private Branch $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);
        $this->cashier = User::factory()->create();
        $this->cashier->organizations()->attach($this->organization->id, ['role' => 'member', 'status' => 'active']);
        $this->till = $this->branch($this->organization, 'Olaya till');
    }

    public function test_the_nearest_choice_wins(): void
    {
        $preferences = app(PrintPreferences::class);
        $organizationId = (string) $this->organization->id;
        $other = $this->branch($this->organization, 'Head office');

        $this->assertSame(PaperSize::A4, $preferences->paper($this->organization, $this->till->id, $this->cashier->id));

        $preferences->setOrganizationPaper($this->organization, PaperSize::A5);
        $preferences->setBranchPaper($organizationId, $this->till->id, PaperSize::Roll80);
        $preferences->setUserPaper($organizationId, $this->cashier->id, PaperSize::Roll58);

        $this->assertSame(PaperSize::Letter, $preferences->paper($this->organization, $this->till->id, $this->cashier->id, 'letter'));
        $this->assertSame(PaperSize::Roll58, $preferences->paper($this->organization, $this->till->id, $this->cashier->id, 'tabloid'));
        $this->assertSame(PaperSize::Roll80, $preferences->paper($this->organization, $this->till->id, $this->admin->id));
        $this->assertSame(PaperSize::A5, $preferences->paper($this->organization, $other->id, $this->admin->id));
        $this->assertSame(PaperSize::A5, $preferences->paper($this->organization, null, null));
    }

    /**
     * A person's paper is theirs in this organization only, and another
     * organization's branch is nobody's to set.
     */
    public function test_choices_stay_in_their_organization(): void
    {
        $preferences = app(PrintPreferences::class);
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $this->cashier->organizations()->attach($rival->id, ['role' => 'member', 'status' => 'active']);
        $rivalTill = $this->branch($rival, 'Rival till');

        $preferences->setUserPaper((string) $this->organization->id, $this->cashier->id, PaperSize::Roll58);
        $preferences->setBranchPaper((string) $this->organization->id, $rivalTill->id, PaperSize::Roll58);

        $this->assertNull($preferences->userPaper((string) $rival->id, $this->cashier->id));
        $this->assertNull($preferences->branchPaper((string) $rival->id, $rivalTill->id));
    }

    /**
     * A cashier saves their own paper, and their print screen opens on it.
     */
    public function test_a_member_saves_their_paper(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2);

        $this->portal($this->cashier)->get(route('portal.printing.edit'))->assertOk()->assertSee(__('Your paper'));
        $this->portal($this->cashier)->post(route('portal.printing.mine'), ['paper' => '58mm'])->assertSessionHas('success');

        $this->portal($this->cashier)->get(route('portal.invoices.print', $invoice->id))
            ->assertOk()
            ->assertSee('/assets/58mm-', false)
            ->assertSee('<option value="58mm" selected>', false);
        $this->portal($this->admin)->get(route('portal.invoices.print', $invoice->id))->assertSee('/assets/a4-', false);
    }

    public function test_only_an_admin_sets_the_organization(): void
    {
        $this->portal($this->cashier)->get(route('portal.printing.edit'))->assertDontSee(__('Organization and branches'));
        $this->portal($this->cashier)->post(route('portal.printing.update'), ['paper' => 'a5'])->assertForbidden();

        $this->portal($this->admin)->post(route('portal.printing.update'), [
            'paper' => 'a5',
            'branches' => [$this->till->id => '80mm'],
        ])->assertSessionHas('success');

        $preferences = app(PrintPreferences::class);
        $this->assertSame(PaperSize::A5, $preferences->organizationPaper($this->organization->fresh()));
        $this->assertSame(PaperSize::Roll80, $preferences->branchPaper((string) $this->organization->id, $this->till->id));
    }

    /**
     * The PDF follows the same choice: the till's roll unless asked for
     * another paper.
     */
    public function test_the_pdf_follows_the_choice(): void
    {
        app(PrintPreferences::class)->setBranchPaper((string) $this->organization->id, $this->till->id, PaperSize::Roll80);
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2, $this->till->id);

        $roll = $this->portal($this->cashier)->get(route('portal.invoices.pdf', $invoice->id))->assertOk()->getContent();
        $sheet = $this->portal($this->cashier)->get(route('portal.invoices.pdf', ['invoiceId' => $invoice->id, 'paper' => 'a5']))->assertOk()->getContent();

        $this->assertEqualsWithDelta(80.0, $this->widthMm((string) $roll), 0.1);
        $this->assertEqualsWithDelta(148.0, $this->widthMm((string) $sheet), 0.1);
    }

    public function test_the_api_takes_a_paper(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2);
        $token = JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'member'])->fromUser($this->cashier);

        $pdf = $this->withToken($token)->get('/api/invoices/'.$invoice->id.'/pdf?paper=58mm')->assertOk()->getContent();

        $this->assertEqualsWithDelta(58.0, $this->widthMm((string) $pdf), 0.1);
        $this->withToken($token)->getJson('/api/invoices/'.$invoice->id.'/pdf?paper=tabloid')->assertStatus(422);
    }

    private function branch(Organization $organization, string $name): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $organization->id,
            'name' => $name,
            'device_serial' => 'EGS-'.str_replace(' ', '-', $name),
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]));
    }

    private function portal(User $user): static
    {
        return $this->actingAs($user)->withSession(['portal_org_id' => $this->organization->id]);
    }

    private function widthMm(string $pdf): float
    {
        $this->assertSame(1, preg_match('#/MediaBox \[0 0 ([\d.]+) #', $pdf, $box));

        return (float) $box[1] * 25.4 / 72;
    }
}
