<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspGatewayResolver;
use App\Domains\Compliance\Networks\Peppol\Gateways\FakeAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\HttpAspGateway;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Sending\PeppolSender;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The provider an organization's documents go through: the platform's
 * partner unless the organization names another configured provider, by
 * purpose key (Electronic Invoicing Guidelines v1.1, p14: each taxpayer
 * appoints its own; p38: tax group members may differ).
 */
class ProviderResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** A literal public address: SafeUrl passes it without a DNS lookup. */
    private const OTHER = 'https://93.184.215.14/other';

    private FakeAspGateway $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = new FakeAspGateway;
        $this->app->instance(AspGateway::class, $this->platform);
        config(['peppol.providers' => [
            'platform' => ['label' => 'Platform partner provider'],
            'second' => ['label' => 'Second provider', 'base_url' => self::OTHER, 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_secret' => 'hook'],
            'unconnected' => ['label' => 'Not connected yet'],
        ]]);
    }

    public function test_platform_provider_by_default(): void
    {
        $organization = $this->organization();

        $this->assertSame('platform', app(AspGatewayResolver::class)->providerFor($organization->id));
        $this->assertSame($this->platform, app(AspGatewayResolver::class)->forOrganization($organization->id));
    }

    public function test_organization_names_another_provider(): void
    {
        $organization = $this->organization('second');

        $this->assertInstanceOf(HttpAspGateway::class, app(AspGatewayResolver::class)->forOrganization($organization->id));
    }

    public function test_unconnected_provider_falls_back(): void
    {
        $organization = $this->organization('unconnected');

        $this->assertSame($this->platform, app(AspGatewayResolver::class)->forOrganization($organization->id));
    }

    /** A document goes through its organization's provider, not the platform's. */
    public function test_document_sent_through_its_provider(): void
    {
        Http::fake([
            self::OTHER.'/oauth/token' => Http::response(['access_token' => 't']),
            self::OTHER.'/documents' => Http::response(['id' => 'second-msg-1'], 201),
        ]);
        $organization = $this->organization('second');
        $document = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::create([
            'org_id' => $organization->id, 'direction' => 'out', 'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1', 'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice', 'xml' => '<Invoice/>', 'status' => 'ready', 'receiver_endpoint' => '0235:1007654321',
        ]));

        app(PeppolSender::class)->send((string) $document->id);

        $this->assertSame(['sent', 'second-msg-1'], [$document->fresh()->status, $document->fresh()->asp_message_id]);
        $this->assertSame([], $this->platform->submitted);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::OTHER.'/documents');
    }

    private function organization(?string $provider = null): Organization
    {
        $organization = Organization::create(['name' => 'Gulf Trading', 'country' => 'AE']);

        if ($provider !== null) {
            app(Customizations::class)->set(AspGatewayResolver::SETTING, Level::Organization, Scope::organization((string) $organization->id), $provider, null);
        }

        return $organization;
    }
}
