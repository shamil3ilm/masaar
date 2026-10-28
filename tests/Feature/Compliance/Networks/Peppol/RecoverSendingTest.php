<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\FakeAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\HttpAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookSignature;
use App\Domains\Compliance\Networks\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A document left in 'sending' by a worker that died mid-call, settled by
 * asking the provider what it holds under the document's own reference
 * (Electronic Invoicing Guidelines v1.1, p46: pending invoices go out once
 * service resumes) - never sent twice, never left behind.
 */
class RecoverSendingTest extends TestCase
{
    use RefreshDatabase;

    private FakeAspGateway $asp;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->asp = new FakeAspGateway;
        $this->app->instance(AspGateway::class, $this->asp);
    }

    public function test_document_the_provider_took_is_sent(): void
    {
        $document = $this->stuck();
        $this->asp->holds((string) $document->id, 'msg-held', 'delivered');

        $this->artisan('peppol:recover-sending')->assertSuccessful();

        $document->refresh();
        $this->assertSame(['delivered', 'msg-held', 'pending'], [$document->status, $document->asp_message_id, $document->reporting_status]);
        $this->assertSame([], $this->asp->submitted);
        Queue::assertNothingPushed();
    }

    public function test_document_never_received_is_sent_again(): void
    {
        $document = $this->stuck();

        $this->artisan('peppol:recover-sending')->assertSuccessful();

        $this->assertSame('ready', $document->fresh()->status);
        Queue::assertPushed(SendPeppolDocument::class, fn (SendPeppolDocument $job): bool => $job->documentId === $document->id);
    }

    public function test_recent_or_unanswered_documents_wait(): void
    {
        $recent = $this->stuck(now()->subMinutes(2));
        $this->artisan('peppol:recover-sending')->assertSuccessful();
        $this->assertSame([], $this->asp->lookedUp);
        $this->assertSame('sending', $recent->fresh()->status);

        $this->app->instance(AspGateway::class, new HttpAspGateway('https://93.184.215.14/api', 'id', 'secret', new WebhookSignature('s')));
        Http::fake(['*' => Http::response([], 503)]);
        $old = $this->stuck();

        $this->artisan('peppol:recover-sending')->assertSuccessful();

        $this->assertSame('sending', $old->fresh()->status);
    }

    public function test_http_lookup_reads_the_reference(): void
    {
        Http::fake([
            'https://93.184.215.14/api/oauth/token' => Http::response(['access_token' => 't']),
            'https://93.184.215.14/api/documents?reference=doc-1' => Http::response(['id' => 'msg-9', 'status' => 'delivered', 'mls_code' => 'AP']),
            'https://93.184.215.14/api/documents?reference=doc-2' => Http::response(['message' => 'not found'], 404),
        ]);
        $gateway = new HttpAspGateway('https://93.184.215.14/api', 'id', 'secret', new WebhookSignature('s'));

        $found = $gateway->findByReference('doc-1');

        $this->assertSame(['msg-9', 'delivered', 'AP'], [$found?->messageId, $found?->status, $found?->mlsCode]);
        $this->assertNull($gateway->findByReference('doc-2'));
    }

    private function stuck(mixed $since = null): PeppolDocument
    {
        $organization = Organization::create(['name' => 'Gulf Trading', 'country' => 'AE']);

        $document = PeppolDocument::acrossTenants()->create([
            'org_id' => $organization->id,
            'direction' => PeppolDocument::DIRECTION_OUT,
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1',
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice',
            'xml' => '<Invoice/>',
            'status' => 'sending',
            'attempts' => 1,
            'receiver_endpoint' => '0235:1007654321',
        ]);
        $document->forceFill(['updated_at' => $since ?? now()->subHour()])->saveQuietly();

        return $document;
    }
}
