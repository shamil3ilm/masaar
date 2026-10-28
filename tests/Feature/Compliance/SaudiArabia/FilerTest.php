<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\DeviceCredentials;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\FatooraClient;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Filer;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use Mockery;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * One place chooses clearance or reporting, from the submission's type.
 *
 * The synchronous filing and the offline drain asked the invoice whether it
 * was B2B, and the queue job asked the submission row, so the same document
 * had two answers to the same question.
 */
class FilerTest extends TestCase
{
    public function test_clearance_type_is_cleared(): void
    {
        $this->assertSame('clearInvoice', $this->sentWith('clearance'));
    }

    public function test_reporting_type_is_reported(): void
    {
        $this->assertSame('reportInvoice', $this->sentWith('reporting'));
    }

    public function test_nothing_else_picks_the_endpoint(): void
    {
        $root = realpath(__DIR__.'/../../../../app');
        $callers = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && preg_match('/->(clearInvoice|reportInvoice)\(/', (string) file_get_contents($file->getPathname()))) {
                $callers[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        $this->assertSame(['Domains/Compliance/Countries/SaudiArabia/Filing/Filer.php'], $callers);
    }

    private function sentWith(string $type): string
    {
        $called = '';
        $client = Mockery::mock(FatooraClient::class);

        foreach (['clearInvoice', 'reportInvoice'] as $method) {
            $client->shouldReceive($method)->andReturnUsing(function () use (&$called, $method): FatooraResponse {
                $called = $method;

                return (new \ReflectionClass(FatooraResponse::class))->newInstanceWithoutConstructor();
            });
        }

        $device = (new \ReflectionClass(DeviceCredentials::class))->newInstanceWithoutConstructor();

        (new Filer($client))->send(new InvoiceSubmission(['submission_type' => $type]), '<xml/>', 'hash', 'uuid', $device);

        return $called;
    }
}
