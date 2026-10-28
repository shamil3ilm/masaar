<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Networks\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The admin console's Peppol page: the gateway, the counts per state across
 * every organization, the documents waiting on someone, and Resend.
 */
class AdminPeppolPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'AE']);
    }

    public function test_page_counts_every_organization(): void
    {
        $globex = Organization::create(['name' => 'Globex', 'country' => 'AE']);
        $this->document($this->acme, 'failed');
        $this->document($globex, 'delivered', 'out', 'reported');
        $this->document($globex, 'received', 'in');

        $this->as('owner')->get('/admin/peppol')
            ->assertOk()
            ->assertViewHas('counts', fn (array $counts): bool => $counts['failed'] === 1 && $counts['reported'] === 1 && $counts['received'] === 1 && $counts['ready'] === 0)
            ->assertViewHas('waiting', fn ($waiting): bool => $waiting->pluck('status')->all() === ['failed'])
            ->assertSee('Acme Trading')
            ->assertSee('No service provider');
    }

    public function test_page_says_the_simulator_is_on(): void
    {
        config(['peppol.gateway' => 'simulator']);

        $this->as('owner')->get('/admin/peppol')->assertOk()->assertSee('Simulator, not for production');
    }

    public function test_resend_queues_a_failed_document(): void
    {
        $document = $this->document($this->acme, 'failed');

        $this->as('owner')->from('/admin/peppol')->post("/admin/peppol/{$document->id}/resend")
            ->assertRedirect('/admin/peppol')
            ->assertSessionHas('success');

        $this->assertSame(['ready', 0], [$document->fresh()->status, $document->fresh()->attempts]);
        Queue::assertPushed(SendPeppolDocument::class, 1);
    }

    public function test_resend_refuses_a_sent_document(): void
    {
        $document = $this->document($this->acme, 'sent');

        $this->as('owner')->from('/admin/peppol')->post("/admin/peppol/{$document->id}/resend")->assertSessionHas('error');

        $this->assertSame('sent', $document->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_resend_needs_operations_permission(): void
    {
        $document = $this->document($this->acme, 'failed');

        $this->as('auditor')->post("/admin/peppol/{$document->id}/resend")->assertForbidden();

        $this->assertSame('failed', $document->fresh()->status);
    }

    private function as(string $role): self
    {
        return $this->actingAs(User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]));
    }

    private function document(Organization $organization, string $status, string $direction = 'out', ?string $reporting = null): PeppolDocument
    {
        return PeppolDocument::acrossTenants()->create([
            'org_id' => $organization->id,
            'direction' => $direction,
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1',
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice',
            'xml' => '<Invoice/>',
            'status' => $status,
            'attempts' => $status === 'failed' ? 5 : 1,
            'asp_message_id' => $status === 'ready' || $status === 'failed' ? null : 'msg-'.uniqid(),
            'sender_endpoint' => '0235:1001234567',
            'receiver_endpoint' => '0235:1007654321',
            'reporting_status' => $reporting,
        ]);
    }
}
