<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A UAE organization's dashboard counts its e-invoices, not ZATCA
 * submissions; a Saudi one's is in JurisdictionIsolationTest.
 */
class UaeDashboardTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_uae_dashboard_counts_einvoices(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = Organization::create(['name' => 'Gulf Trading LLC', 'country' => 'AE', 'status' => 'active']);
        $rival = Organization::create(['name' => 'Rival LLC', 'country' => 'AE', 'status' => 'active']);

        foreach (['ready', 'sending', 'sent', 'delivered', 'reported', 'rejected', 'failed', 'failed'] as $status) {
            $this->document($gulf, PeppolDocument::DIRECTION_OUT, $status);
        }
        $this->document($gulf, PeppolDocument::DIRECTION_IN, 'received');
        $this->document($rival, PeppolDocument::DIRECTION_OUT, 'delivered');

        $this->asRequest(fn () => $this->actingAs($this->member($gulf))
            ->get('/portal')
            ->assertOk()
            ->assertViewHas('einvoices', ['awaiting' => 3, 'delivered' => 2, 'rejected' => 1, 'failed' => 2, 'received' => 1, 'reported' => 1, 'report_failed' => 0, 'not_exchanged' => 0])
            ->assertViewHas('recentDocuments', fn ($documents) => $documents->count() === 8)
            ->assertViewMissing('certificate')
            ->assertSee('Your UAE e-invoicing overview')
            ->assertSee('Recent e-invoices')
            ->assertSee('Awaiting delivery')
            ->assertDontSee('Your ZATCA compliance overview')
            ->assertDontSee('Recent Submissions')
            ->assertDontSee('Activity by User (7 days)'));
    }

    private function member(Organization $organization): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'member', 'status' => 'active']);

        return $user;
    }

    private function document(Organization $organization, string $direction, string $status): void
    {
        $this->sequence++;

        PeppolDocument::withoutTenantScope(fn () => PeppolDocument::create([
            'org_id' => $organization->id,
            'direction' => $direction,
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1',
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice',
            // 'reported' is a report to the authority, on a delivered document.
            'status' => $status === 'reported' ? 'delivered' : $status,
            'reporting_status' => $status === 'reported' ? 'reported' : null,
            'asp_message_id' => 'msg-'.$this->sequence,
            'xml' => '<Invoice/>',
        ]));
    }
}
