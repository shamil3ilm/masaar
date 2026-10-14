<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Models\OfflineItem;
use App\Domains\Compliance\Fatoora\Services\OfflineQueue;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two drains never send one item, and a dead drain does not strand one.
 *
 * The drain fetched a batch and marked each item processing only when it got
 * to it, with an unconditional update. Its overlap lock lapses after ten
 * minutes, and fifty slow calls to the authority take longer, so a second
 * drain could start, fetch the same still-pending items, and both would send
 * them.
 *
 * The other half: a drain killed mid-item leaves it processing, which no
 * drain picks up again - and submission recovery leaves offline invoices to
 * the drain - so the invoice was never filed. A stale item goes back to
 * pending if it is a report, which ZATCA has been seen to accept twice; a
 * clearance is held for reconciliation, as submission recovery holds it.
 */
class OfflineDrainTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fatoora.recovery.stale_minutes' => 15]);
        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
    }

    public function test_an_item_is_claimed_once(): void
    {
        $item = $this->item('simplified', 'pending');
        $queue = app(OfflineQueue::class);

        $this->assertTrue($queue->claim($item->id), 'the first drain should take it');
        $this->assertFalse($queue->claim($item->id), 'a second drain took it as well');
    }

    public function test_a_stranded_report_is_released(): void
    {
        $item = $this->item('simplified', 'processing', startedMinutesAgo: 30);

        app(OfflineQueue::class)->releaseStranded();

        $this->assertSame('pending', $item->fresh()->state);
    }

    public function test_a_stranded_clearance_is_held(): void
    {
        $item = $this->item('standard', 'processing', startedMinutesAgo: 30);

        app(OfflineQueue::class)->releaseStranded();

        $this->assertSame('processing', $item->fresh()->state);
    }

    public function test_a_running_item_is_left_alone(): void
    {
        $item = $this->item('simplified', 'processing', startedMinutesAgo: 2);

        app(OfflineQueue::class)->releaseStranded();

        $this->assertSame('processing', $item->fresh()->state);
    }

    private function item(string $type, string $state, int $startedMinutesAgo = 0): OfflineItem
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-'.uniqid(),
            'type' => $type,
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
            'icv' => random_int(1, 100000),
            'hash' => base64_encode(random_bytes(32)),
        ]));

        $item = OfflineItem::withoutTenantScope(fn () => OfflineItem::create([
            'org_id' => $this->acme->id,
            'invoice_id' => $invoice->id,
            'signed_xml' => '<Invoice/>',
            'invoice_hash' => (string) $invoice->hash,
            'qr_code' => base64_encode('qr'),
            'state' => $state,
            'queued_at' => now()->subHour(),
        ]));

        $item->forceFill(['started_at' => $state === 'processing' ? now()->subMinutes($startedMinutesAgo) : null])->save();

        return $item;
    }
}
