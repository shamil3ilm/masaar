<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Compliance\Networks\Peppol\Sending\DocumentStatus;
use Tests\TestCase;

/**
 * A Peppol document's two tracks, every pair of statuses on each: the moves
 * DocumentStatus documents are the only ones it allows. Written out by hand,
 * so a change to the table has to be made here too.
 */
class DocumentStatusTableTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const DELIVERY = [
        'ready' => ['sending', 'failed'],
        'sending' => ['ready', 'sent', 'not_exchanged', 'rejected', 'failed'],
        'sent' => ['delivered', 'rejected'],
        'failed' => ['ready'],
        'delivered' => [],
        'rejected' => [],
        'received' => [],
        'not_exchanged' => [],
        'not_transmitted' => [],
    ];

    /** @var array<string, list<string>> */
    private const REPORTING = [
        '' => ['pending', 'reported', 'report_failed'],
        'pending' => ['reported', 'report_failed'],
        'report_failed' => ['pending', 'reported'],
        'reported' => [],
    ];

    public function test_delivery_moves_follow_the_table(): void
    {
        $status = app(DocumentStatus::class);

        foreach (self::DELIVERY as $from => $targets) {
            foreach (array_keys(self::DELIVERY) as $to) {
                $this->assertSame(in_array($to, $targets, true), $status->allows($from, $to), "{$from} -> {$to}");
            }
        }
    }

    public function test_reporting_moves_follow_the_table(): void
    {
        $status = app(DocumentStatus::class);

        foreach (self::REPORTING as $from => $targets) {
            foreach (array_keys(self::REPORTING) as $to) {
                if ($to === '') {
                    continue;
                }
                $this->assertSame(in_array($to, $targets, true), $status->allowsReporting($from === '' ? null : $from, $to), "{$from} -> {$to}");
            }
        }
    }

    /** Issued before e-invoicing started: kept, and never sent afterwards. */
    public function test_not_transmitted_has_no_exit(): void
    {
        $status = app(DocumentStatus::class);

        foreach (array_keys(self::DELIVERY) as $to) {
            $this->assertFalse($status->allows(DocumentStatus::NOT_TRANSMITTED, $to), "not_transmitted -> {$to}");
        }
    }
}
