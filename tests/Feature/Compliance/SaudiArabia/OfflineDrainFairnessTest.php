<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\DeviceCredentials;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\FatooraClient;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineQueue;
use App\Domains\Compliance\Countries\SaudiArabia\Models\OfflineItem;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The offline backlog drains fairly, and in bulk.
 *
 * Each run took up to fifty items from one organization, then the next, in
 * whatever order the database gave them: an organization with a long outage
 * behind it held every other one back until its fifty were sent, and fifty
 * every five minutes is 14,400 a day - short of a busy store's day after a
 * long outage, against a twenty-four-hour reporting window. Organizations
 * now take turns in slices, the oldest waiting first, up to a configurable
 * number each, within a time budget that ends before the next run.
 */
class OfflineDrainFairnessTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> invoice ids, in the order they were sent */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->partialMock(CredentialStore::class, function (MockInterface $mock): void {
            $mock->shouldReceive('certificate')->andReturn('certificate');
            $mock->shouldReceive('forInvoice')->andReturn(new DeviceCredentials('token', 'secret'));
        });

        $this->mock(FatooraClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reportInvoice')->andReturnUsing(function (string $xml, string $hash, string $uuid): FatooraResponse {
                $this->sent[] = $uuid;

                return FatooraResponse::fromApiResponse(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']]);
            });
        });
    }

    public function test_organizations_take_turns(): void
    {
        config(['fatoora.offline.drain.slice' => 2, 'fatoora.offline.drain.per_organization' => 100]);

        $busy = $this->backlog('Busy Market', 5, waitingSinceMinutes: 90);
        $small = $this->backlog('Corner Shop', 2, waitingSinceMinutes: 30);

        $this->drain();

        $this->assertSame([$busy, $busy, $small, $small, $busy, $busy, $busy], $this->sentBy());
    }

    public function test_each_organization_is_capped(): void
    {
        config(['fatoora.offline.drain.slice' => 2, 'fatoora.offline.drain.per_organization' => 3]);

        $busy = $this->backlog('Busy Market', 5, waitingSinceMinutes: 90);
        $small = $this->backlog('Corner Shop', 2, waitingSinceMinutes: 30);

        $this->drain();

        $this->assertSame([$busy, $busy, $small, $small, $busy], $this->sentBy());
        $this->assertSame(2, OfflineItem::withoutTenantScope(fn () => OfflineItem::query()->where('state', OfflineQueue::STATE_PENDING)->count()));
    }

    private function drain(): void
    {
        $this->artisan('fatoora:process-offline', ['--force' => true])->assertSuccessful();
    }

    /**
     * @return list<string> the organization of each invoice sent, in order
     */
    private function sentBy(): array
    {
        $owners = Invoice::withoutTenantScope(fn () => Invoice::query()->whereIn('id', $this->sent)->pluck('org_id', 'id'));

        return array_map(fn (string $id): string => (string) $owners[$id], $this->sent);
    }

    private function backlog(string $name, int $count, int $waitingSinceMinutes): string
    {
        $organization = Organization::create(['name' => $name, 'country' => 'SA']);

        for ($i = 1; $i <= $count; $i++) {
            $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
                'org_id' => $organization->id,
                'invoice_number' => $name.'-'.$i,
                'type' => 'simplified',
                'status' => 'issued',
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'subtotal' => '100.00',
                'tax_amount' => '15.00',
                'total' => '115.00',
                'icv' => $i,
                'hash' => base64_encode(random_bytes(32)),
            ]));

            OfflineItem::withoutTenantScope(fn () => OfflineItem::create([
                'org_id' => $organization->id,
                'invoice_id' => $invoice->id,
                'signed_xml' => '<Invoice/>',
                'invoice_hash' => (string) $invoice->hash,
                'qr_code' => base64_encode('qr'),
                'state' => OfflineQueue::STATE_PENDING,
                'queued_at' => now()->subMinutes($waitingSinceMinutes - $i),
                'next_attempt_at' => now()->subMinute(),
            ]));
        }

        return (string) $organization->id;
    }
}
