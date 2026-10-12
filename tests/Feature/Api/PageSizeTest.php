<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Http\Pagination\PageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * A caller cannot choose a page size that removes the page.
 *
 * per_page was read off the query string and cast to int. Two values did
 * something nobody would ask for on purpose:
 *
 *   0 returns every row. Laravel reads it as no limit rather than dividing by
 *   it, so the parameter whose job is to bound the answer was also how to
 *   remove the bound - on /api/v1/invoices that is every invoice the
 *   organization has, in one response.
 *
 *   -1 reaches the database as LIMIT -1, which is a syntax error, so the
 *   request is a 500.
 *
 * Both were confirmed against the development database before this was
 * written: paginate(0) returned all seven invoices, paginate(-1) raised a
 * QueryException. /api/v1/invoices and the webhook delivery log passed the
 * value through untouched; the licence list capped the top and not the
 * bottom, which prevents neither.
 */
class PageSizeTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    /**
     * @return list<array{0: string, 1: int, 2: string}>
     */
    public static function sizes(): array
    {
        return [
            'zero means the default, not everything' => ['0', 15, 'zero was read as no limit'],
            'negative means the default too' => ['-1', 15, 'a negative reached the database as LIMIT -1'],
            'text means the default' => ['abc', 15, 'a non-numeric value cast to zero, which meant no limit'],
            'absent means the default' => ['', 15, 'the default was not applied'],
            'above the cap is capped' => ['100000', PageSize::MAX, 'one request could ask for the whole table'],
            'a sensible value is honoured' => ['7', 7, 'a reasonable page size was not respected'],
        ];
    }

    #[DataProvider('sizes')]
    public function test_the_page_size_is_clamped(string $asked, int $expected, string $otherwise): void
    {
        $request = Request::create('/api/v1/invoices'.($asked === '' ? '' : '?per_page='.$asked));

        $this->assertSame($expected, PageSize::fromRequest($request, 15),
            'per_page='.$asked.': '.$otherwise.'.');
    }

    /**
     * And the endpoint itself honours it.
     *
     * The clamp is a function and could be correct while nothing called it,
     * which is how the licence list came to cap one end only. This asks the
     * endpoint.
     */
    public function test_the_invoice_list_bounds_its_answer(): void
    {
        $organization = Organization::create([
            'name' => 'Paging Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
        ]);

        $user = User::factory()->create(['email' => 'paging@masaar.test']);
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);

        foreach (range(1, 20) as $n) {
            $this->makeInvoice($organization, 'INV-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT));
        }

        $token = (string) $this->postJson('/api/auth/login', [
            'email' => 'paging@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');

        $issued = License::createWithCredentials([
            'org_id' => $organization->id,
            'organization_name' => 'Paging Trading',
            'contact_email' => 'paging@masaar.test',
            'tier' => 'starter',
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-API-Key' => $issued['api_key'],
            'X-API-Secret' => $issued['api_secret'],
        ];

        $response = $this->withHeaders($headers)->getJson('/api/v1/invoices?per_page=0');

        $response->assertOk();

        $returned = $response->json('data');

        $this->assertIsArray($returned);
        $this->assertLessThanOrEqual(15, count($returned),
            'per_page=0 returned '.count($returned).' invoices. It used to mean no limit, '
            .'which made it a way to ask for the whole table in one answer.');

        // And the request that was a 500.
        $this->withHeaders($headers)
            ->getJson('/api/v1/invoices?per_page=-1')
            ->assertOk();
    }
}
