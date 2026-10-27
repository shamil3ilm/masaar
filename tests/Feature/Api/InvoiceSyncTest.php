<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * An ERP keeping its copy of the invoices in step: GET /api/v1/invoices with
 * a cursor walks every invoice once, oldest change first, and updated_since
 * starts the walk where the last one ended.
 */
class InvoiceSyncTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    private Organization $organization;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));
        $this->organization = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => 'active']);
        License::create([
            'org_id' => $this->organization->id, 'api_key' => 'cp_test_sync', 'api_secret_hash' => License::hashSecret('sync-secret'),
            'organization_name' => 'Acme', 'contact_email' => 'ops@example.test', 'environment' => 'sandbox',
            'tier' => 'enterprise', 'status' => 'active', 'scopes' => ['invoice.read'],
        ]);
        $this->headers = ['X-API-Key' => 'cp_test_sync', 'X-API-Secret' => 'sync-secret'];
    }

    public function test_the_cursor_walks_each_once(): void
    {
        // Three in one second, so the id has to break the tie.
        $made = [];
        foreach (range(1, 7) as $n) {
            $made[] = $this->makeInvoice($this->organization, 'INV-'.$n)->id;

            if ($n % 3 === 0) {
                $this->travel(1)->seconds();
            }
        }

        $seen = $this->walk('/api/v1/invoices?per_page=3&cursor=');

        $this->assertCount(7, $seen);
        $this->assertEqualsCanonicalizing($made, $seen);
    }

    public function test_a_change_comes_again_later(): void
    {
        $first = $this->makeInvoice($this->organization, 'INV-1');
        $this->travel(1)->seconds();
        $this->makeInvoice($this->organization, 'INV-2');

        $page = $this->getJson('/api/v1/invoices?per_page=1&cursor=', $this->headers)->assertOk();
        $this->assertSame($first->id, $page->json('data.0.id'));

        $this->travel(1)->seconds();
        Invoice::withoutTenantScope(fn () => $first->forceFill(['notes' => 'Changed'])->save());

        $rest = $this->walk('/api/v1/invoices?per_page=1&cursor='.$page->json('meta.next_cursor'));

        $this->assertSame([$first->id], array_slice($rest, -1), 'the changed invoice comes again, as it now is');
        $this->assertCount(2, $rest);
    }

    public function test_updated_since_limits_both_pagings(): void
    {
        $this->makeInvoice($this->organization, 'INV-OLD');
        $this->travel(1)->hours();
        $new = $this->makeInvoice($this->organization, 'INV-NEW');
        $since = urlencode(now()->subMinute()->toIso8601String());

        $this->getJson('/api/v1/invoices?updated_since='.$since.'&cursor=', $this->headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('meta.next_cursor', null);

        $this->getJson('/api/v1/invoices?updated_since='.$since, $this->headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $new->id);
    }

    public function test_page_numbers_still_work(): void
    {
        foreach (range(1, 3) as $n) {
            $this->makeInvoice($this->organization, 'INV-'.$n);
        }

        $this->getJson('/api/v1/invoices?per_page=2&page=2', $this->headers)
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(1, 'data');
    }

    /** And an updated_since that is not a date and time. */
    public function test_a_bad_cursor_is_refused(): void
    {
        $this->getJson('/api/v1/invoices?cursor=not-ours', $this->headers)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrorFor('cursor', 'error.details');

        $this->getJson('/api/v1/invoices?updated_since=yesterday-ish', $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('updated_since', 'error.details');
    }

    public function test_another_organizations_are_not_walked(): void
    {
        $this->makeInvoice(Organization::create(['name' => 'Rival', 'country' => 'SA', 'status' => 'active']), 'THEIRS');

        $this->assertSame([], $this->walk('/api/v1/invoices?cursor='));
    }

    /**
     * Every id the walk returns, following next_cursor to the end.
     *
     * @return list<string>
     */
    private function walk(string $url): array
    {
        $seen = [];

        for ($pages = 0; $url !== null && $pages < 20; $pages++) {
            $page = $this->getJson($url, $this->headers)->assertOk();
            $seen = [...$seen, ...array_column($page->json('data'), 'id')];
            $next = $page->json('meta.next_cursor');
            $url = $next === null ? null : preg_replace('/cursor=[^&]*/', 'cursor='.$next, $url);
        }

        return $seen;
    }
}
