<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A VAT number is checked where it is typed.
 *
 * Four places accepted 'max:50' of any characters. Two of them write to a
 * column that holds fifteen - organizations.vat_number and
 * licenses.organization_vat - and MySQL runs in strict mode, so a sixteenth
 * character was a 500 carrying a SQL message rather than a 422 naming the
 * field. On SQLite, which this suite uses, the same input is silently
 * truncated instead: a tax platform storing a VAT number that is not the one
 * the taxpayer gave it.
 *
 * The other two reach the authority. ZATCA's BR-KSA-44 requires a buyer VAT
 * number of fifteen digits, and a rejection for breaking it arrives at
 * submission - after the invoice has been issued, numbered, hashed and
 * written into the chain. The remedy for a rejected invoice is a credit note,
 * so a typo cost a credit note instead of a form error.
 *
 * Fifteen digits and nothing about the first or last one. BR-KSA-40 and
 * BR-KSA-44 require both to be "3", and that belongs where the jurisdiction
 * is known: this platform also files for the UAE, whose TRN is fifteen digits
 * with no such prefix.
 */
class VatNumberTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create(['email' => 'admin@example.test']);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
        ])->json('data.token.access_token');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'sixteen digits' => ['3123456789012345', 'longer than the column'],
            'fourteen digits' => ['31234567890123', 'too short'],
            'letters' => ['ABCDEFGHIJKLMNO', 'right length, not a number'],
            'spaces' => ['3 1234567890 123', 'spaced'],
            'fifty characters' => [str_repeat('3', 50), 'the old maximum'],
        ];
    }

    /**
     * A VAT number the column cannot hold is a 422, not a 500.
     */
    #[DataProvider('refusals')]
    public function test_a_bad_vat_is_refused(string $vat, string $why): void
    {
        $this->withToken($this->token)
            ->postJson('/api/organizations', ['name' => 'Acme', 'vat_number' => $vat])
            ->assertStatus(422)
            ->assertJsonPath('error.details.vat_number.0', 'The VAT number must be exactly 15 digits.');

        $this->assertSame(0, Organization::count(), $why);
    }

    public function test_a_good_vat_is_accepted(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/organizations', ['name' => 'Acme', 'vat_number' => '300000000000003'])
            ->assertStatus(201);

        $this->assertSame(
            '300000000000003',
            Organization::first()->vat_number
        );
    }

    /**
     * And it stays optional, because a Phase 1 organization may not have one
     * yet.
     */
    public function test_no_vat_is_still_accepted(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/organizations', ['name' => 'Acme'])
            ->assertStatus(201);
    }
}
