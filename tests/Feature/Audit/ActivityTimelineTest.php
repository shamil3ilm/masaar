<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Contracts\Seal;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IssuingLifecycle;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * An organization's owners read who did what: issued and corrected, changed
 * the company and its branches, invited whom - with the list's filters,
 * and none of another organization's or the platform staff's entries.
 */
class ActivityTimelineTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    private Organization $acme;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => 'active']);
        $this->owner = $this->memberOf('admin', 'Omar Owner');
        $this->cashier = $this->memberOf('member', 'Sara Cashier');
    }

    public function test_an_issued_invoice_names_its_issuer(): void
    {
        $this->issueAs($this->cashier, 'INV-77');

        $this->page($this->owner, '/portal/activity')
            ->assertOk()
            ->assertSee('Issued invoice INV-77')
            ->assertSee('Sara Cashier');

        $this->assertDatabaseHas('audit_logs', ['org_id' => $this->acme->id, 'action' => 'invoice.issued', 'user_id' => $this->cashier->id]);
    }

    public function test_invitations_and_joins_are_shown(): void
    {
        $this->entry('security.member.invited', $this->owner, ['email' => 'new@acme.test', 'role' => 'viewer']);
        // Joining from the link is written before the person is a tenant of
        // it, so the entry names the organization rather than holding it.
        AuditLog::create(['org_id' => null, 'user_id' => $this->cashier->id, 'action' => 'security.member.joined', 'entity_type' => 'Organization', 'entity_id' => $this->acme->id, 'metadata' => ['role' => 'member']]);

        $this->page($this->owner, '/portal/activity')
            ->assertSee('Invited new@acme.test as a viewer')
            ->assertSee('Joined as a member');
    }

    public function test_others_entries_are_not_shown(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        AuditLog::create(['org_id' => $rival->id, 'action' => 'Organization.updated', 'entity_type' => 'Organization', 'entity_id' => $rival->id]);
        AuditLog::create(['org_id' => null, 'action' => 'admin.organizations.approve', 'entity_type' => 'Organization', 'entity_id' => $this->acme->id, 'ip_address' => '203.0.113.9']);
        $this->entry('Organization.updated', $this->owner);

        $this->page($this->owner, '/portal/activity')
            ->assertSee('Changed the company')
            ->assertViewHas('entries', fn ($page): bool => $page->total() === 1)
            ->assertDontSee('203.0.113.9');
    }

    public function test_filters_narrow_by_person_and_kind(): void
    {
        $this->entry('Organization.updated', $this->owner);
        $this->entry('Invoice.created', $this->cashier, [], ['invoice_number' => 'DRAFT-9']);

        $this->page($this->owner, '/portal/activity?who='.$this->cashier->id)
            ->assertSee('Started draft DRAFT-9')
            ->assertDontSee('Changed the company');

        $this->page($this->owner, '/portal/activity?kind=settings')
            ->assertSee('Changed the company')
            ->assertDontSee('DRAFT-9');
    }

    public function test_the_csv_follows_the_filters(): void
    {
        $this->entry('Organization.updated', $this->owner);
        $this->entry('Invoice.created', $this->cashier, [], ['invoice_number' => 'DRAFT-9']);

        $csv = $this->asRequest(fn (): string => $this->actingAs($this->owner)->get('/portal/activity/export.csv?kind=invoices')->assertOk()->streamedContent());

        $this->assertStringContainsString('Started draft DRAFT-9', $csv);
        $this->assertStringNotContainsString('Changed the company', $csv);
    }

    public function test_only_administrators_read_it(): void
    {
        $this->page($this->cashier, '/portal/activity')->assertForbidden();
        $this->page($this->cashier, '/portal/activity/export.csv')->assertForbidden();
    }

    private function issueAs(User $user, string $number): void
    {
        $this->actingAs($user);
        $draft = $this->makeInvoice($this->acme, $number, ['document_type' => 'invoice']);

        app(IssuingLifecycle::class)->issue($draft, $this->acme, new class implements IssuingJurisdiction
        {
            public function refuseIssued(Invoice $invoice): \Throwable
            {
                return new \RuntimeException('issued');
            }

            public function refuseIssuedMeanwhile(Invoice $invoice): \Throwable
            {
                return new \RuntimeException('issued meanwhile');
            }

            public function prepare(Invoice $invoice, Organization $organization, bool $reissue): mixed
            {
                return null;
            }

            public function lockScope(Invoice $invoice, mixed $context): string
            {
                return 'test';
            }

            public function finished(Invoice $invoice): mixed
            {
                return null;
            }

            public function beforeNumbering(Invoice $invoice, mixed $context): void {}

            public function seal(Invoice $invoice, Organization $organization, mixed $context): Seal
            {
                return new Seal(columns: []);
            }

            public function afterIssued(Invoice $invoice, mixed $context, Seal $seal): mixed
            {
                return null;
            }

            public function afterCommit(Invoice $invoice, mixed $result, bool $issuedNow): void {}
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $values
     */
    private function entry(string $action, User $by, array $metadata = [], array $values = []): AuditLog
    {
        return AuditLog::create([
            'org_id' => $this->acme->id, 'user_id' => $by->id, 'action' => $action,
            'entity_type' => 'Organization', 'entity_id' => $this->acme->id,
            'metadata' => $metadata, 'new_values' => $values,
        ]);
    }

    private function page(User $user, string $uri): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)->get($uri));
    }

    private function memberOf(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
