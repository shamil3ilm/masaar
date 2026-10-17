<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use App\Domains\Platform\Services\KillSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The portal's issue action asks Access: the invoice page offers the button
 * only when issuing is allowed and otherwise says why, and a refused issue
 * is flashed with its reason while the draft stays a draft.
 */
class IssueAccessTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'access-token';

    private Organization $acme;

    private Invoice $draft;

    protected function setUp(): void
    {
        parent::setUp();

        // The default module, so no country is named.
        $code = app(JurisdictionRegistry::class)->defaultCode();
        app(JurisdictionAvailability::class)->set($code, true, 'ops@masaar.test');

        $this->acme = Organization::create(['name' => 'Acme', 'country' => $code, 'status' => Organization::STATUS_ACTIVE]);
        $this->draft = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'INV-1',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => app(JurisdictionRegistry::class)->get($code)->currency(),
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
    }

    public function test_button_shows_when_allowed(): void
    {
        $this->page($this->member('member'))
            ->assertOk()
            ->assertSee(route('portal.invoices.issue', $this->draft->id), false)
            ->assertDontSee('Cannot be issued now');
    }

    /** A viewer sees no button and no explanation: reading is all they do. */
    public function test_viewer_sees_no_button(): void
    {
        $this->page($this->member('viewer'))
            ->assertOk()
            ->assertDontSee(route('portal.invoices.issue', $this->draft->id), false)
            ->assertDontSee('Cannot be issued now');
    }

    /** Anyone else who cannot issue now is told why, instead of a button. */
    public function test_refusal_replaces_the_button(): void
    {
        $this->acme->forceFill(['status' => Organization::STATUS_PENDING_REVIEW])->save();

        $this->page($this->member('admin'))
            ->assertOk()
            ->assertDontSee(route('portal.invoices.issue', $this->draft->id), false)
            ->assertSee('Cannot be issued now')
            ->assertSee('being reviewed');
    }

    /**
     * A refused issue says why - a stop on the whole platform, or a platform
     * admin's switch for this company - and the draft stays a draft.
     */
    public function test_refused_issue_flashes_reason(): void
    {
        $member = $this->member('member');

        app(KillSwitch::class)->enable(KillSwitch::SWITCH_ISSUANCE, reason: 'Signing defect');
        $this->issue($member)
            ->assertRedirect(route('portal.invoices.show', $this->draft->id))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'Paused on the whole platform') && str_contains($m, 'Signing defect'));
        app(KillSwitch::class)->resumeAll();

        app(FeatureOverrides::class)->set((string) $this->acme->id, Feature::IssueInvoices, 'off', 'Disputed invoices', 'ops@masaar.test');
        $this->issue($member)
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'Switched off for this company') && str_contains($m, 'Disputed invoices'));

        $this->assertSame(InvoiceStatus::Draft, $this->draft->fresh()->status);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function page(User $user): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)
            ->withSession(['portal_org_id' => $this->acme->id])
            ->get('/portal/invoices/'.$this->draft->id));
    }

    private function issue(User $user): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)
            ->withSession(['portal_org_id' => $this->acme->id, '_token' => self::TOKEN])
            ->post('/portal/invoices/'.$this->draft->id.'/issue', ['_token' => self::TOKEN]));
    }
}
