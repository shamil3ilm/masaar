<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Openapi\RequestDescriber;
use App\Console\Commands\Openapi\ResponseDescriber;
use App\Console\Commands\Openapi\RouteReader;
use App\Domains\Webhook\Services\WebhookService;
use App\Http\Responses\ResponseSchemas;
use App\Support\Brand;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Write the OpenAPI description from the routes that actually exist.
 *
 * The specification was maintained by hand and drifted: it described sixteen
 * paths out of a hundred and twenty-nine, documented a prefix that now answers
 * 301, and omitted the licence-authenticated surface integrators actually use.
 * A description that disagrees with the API is worse than none — someone
 * writes an integration against it and blames the platform when it 404s.
 *
 * Generating it removes the way it drifts. OpenapiDriftTest runs this and
 * fails when the committed file differs, so adding a route without describing
 * it breaks the build rather than the next integrator.
 *
 * Everything here is read from something: paths and methods from the router,
 * credentials and scopes from the middleware, request bodies from the
 * FormRequest a controller asks for, and the response envelope from the
 * ApiResponse factory its method calls.
 *
 * What sits inside `data` is described where the controller method declares
 * it (#[ResponseData], with the shapes in ResponseSchemas), and nowhere
 * else: the contract tests send real requests and hold each declared answer
 * to it, so the claim is enforced - an undeclared one is how the hand-written
 * specification came to disagree with the API.
 */
class GenerateOpenapi extends Command
{
    protected $signature = 'platform:openapi
                            {--path=docs/openapi.yaml : Where to write}
                            {--reference=resources/developers/openapi.json : Where to write the copy the developer page reads}
                            {--print : Write the YAML to stdout instead of the files}
                            {--print-reference : Write the developer page copy to stdout instead of the files}';

    protected $description = 'Generate the OpenAPI description from the route table';

    /**
     * The paths /developers publishes, by prefix: the partner API, the
     * sign-in that gives a customer's people their token, and the tenant API
     * they call with it. Anything not listed - the console (/api/admin), the
     * probes (/api/health), the metrics scrape, the network provider's
     * callbacks - is the platform's own and is not published.
     */
    private const PUBLISHED = [
        '/api/v1',
        '/api/auth',
        '/api/invoices',
        '/api/customers',
        '/api/products',
        '/api/schedules',
        '/api/settings/numbering',
        '/api/organizations',
        '/api/compliance',
        '/api/webhooks',
        '/api/dashboard',
    ];

    /**
     * And by path: what a licence reads about itself. Not the whole of
     * /api/license, whose /status is the deployment's own licence.
     */
    private const PUBLISHED_PATHS = [
        '/api/license',
        '/api/license/features/{feature}',
        '/api/license/health',
        '/api/license/quotas',
        '/api/license/usage',
    ];

    /** @var array<string, true> paths with a route behind platform.admin, never published */
    private array $adminOnly = [];

    public function __construct(
        private readonly RouteReader $reader,
        private readonly RequestDescriber $requests,
        private readonly ResponseDescriber $responses,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => Brand::name('en').' Compliance API',
                'version' => (string) config('app.version', '1.0.0'),
                'description' => 'GCC e-invoicing compliance. Generated from the route table by '
                    .'`php artisan platform:openapi` — edit the routes, not this file.',
            ],
            'servers' => [
                ['url' => 'https://api.example.com', 'description' => 'Production'],
                ['url' => 'https://sandbox.example.com', 'description' => 'Sandbox'],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                    'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                    'apiSecret' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Secret'],
                    // Operational rather than customer-facing: the scrape
                    // endpoint admits an allowlisted source IP or this token,
                    // and is closed when neither is configured.
                    'metricsToken' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
                'schemas' => [...$this->responses->envelopes(), 'WebhookEvent' => $this->webhookEvent(), ...ResponseSchemas::components()],
            ],
            'paths' => $this->paths(),
            // What the platform sends a subscribed endpoint (WebhookService).
            'webhooks' => [
                'delivery' => [
                    'post' => [
                        'summary' => 'An event, POSTed to each webhook subscribed to it, signed in X-Webhook-Signature',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/WebhookDelivery']]],
                        ],
                        'responses' => ['200' => ['description' => 'Any 2xx is delivered; anything else is retried']],
                    ],
                ],
            ],
        ];

        // The layout is pinned rather than left to the library's default,
        // which changed in symfony/yaml 8.1.6: a patch upgrade rewrote the
        // whole committed spec, and the drift test read that as a changed API.
        $yaml = Yaml::dump($spec, 10, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_COMPACT_NESTED_MAPPING);

        // The public part of the same description as JSON, for the public
        // developer page (/developers). docs/ is not part of a production
        // image and the YAML parser is a development dependency, so the page
        // reads this copy, written beside the YAML from the same array; the
        // drift test holds it equal to the routes as the YAML is held.
        // Without the title: the page names the product from the brand when
        // it renders, so a rename reaches it without regenerating.
        $reference = $this->published($spec);
        unset($reference['info']['title']);
        $json = json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if ($this->option('print')) {
            $this->line($yaml);

            return self::SUCCESS;
        }

        if ($this->option('print-reference')) {
            $this->line($json);

            return self::SUCCESS;
        }

        $path = base_path((string) $this->option('path'));
        file_put_contents($path, $yaml);

        $reference = base_path((string) $this->option('reference'));

        if (! is_dir(dirname($reference))) {
            mkdir(dirname($reference), 0755, true);
        }

        file_put_contents($reference, $json);

        $this->info(sprintf('Wrote %d paths to %s', count($spec['paths']), $path));

        return self::SUCCESS;
    }

    /**
     * What /developers publishes: the partner API and the API a customer's
     * own people call, and only those (PUBLISHED, PUBLISHED_PATHS), with the
     * security schemes they use. The console's and the platform's routes,
     * the probes, the metrics scrape and the provider's callbacks stay in
     * docs/openapi.yaml for the team, and off the public page; a route
     * behind platform.admin is left out whatever its path.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function published(array $spec): array
    {
        $paths = array_filter(
            $spec['paths'],
            fn (string $path): bool => ! isset($this->adminOnly[$path]) && $this->isPublished($path),
            ARRAY_FILTER_USE_KEY,
        );

        $schemes = [];

        foreach ($paths as $operations) {
            foreach ($operations as $operation) {
                foreach ($operation['security'] ?? [] as $requirement) {
                    $schemes += array_flip(array_keys($requirement));
                }
            }
        }

        $spec['paths'] = $paths;
        $spec['components']['securitySchemes'] = array_intersect_key($spec['components']['securitySchemes'], $schemes);

        return $spec;
    }

    private function isPublished(string $path): bool
    {
        if (in_array($path, self::PUBLISHED_PATHS, true)) {
            return true;
        }

        foreach (self::PUBLISHED as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The events a webhook may subscribe to, as WebhookService::EVENTS
     * lists them, and "*" for all of them: the `events` the webhook routes
     * take.
     *
     * @return array<string, mixed>
     */
    private function webhookEvent(): array
    {
        return [
            'type' => 'string',
            'description' => 'An event a webhook subscribes to (WebhookService::EVENTS), or "*" for every one',
            'enum' => [...WebhookService::EVENTS, '*'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function paths(): array
    {
        $paths = [];

        foreach ($this->apiRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            if (in_array('platform.admin', $route->gatherMiddleware(), true)) {
                $this->adminOnly[$uri] = true;
            }

            foreach ($route->methods() as $method) {
                // HEAD is implied by GET, and OPTIONS is transport.
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$uri][strtolower($method)] = $this->operation($route, $method);
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @return list<Route>
     */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route) => str_starts_with($route->uri(), 'api/')
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, string $method): array
    {
        $operation = [
            'tags' => [$this->tag($route)],
            'operationId' => $this->operationId($route, $method),
            'summary' => $this->summary($route),
        ];

        if ($parameters = $this->requests->parameters($route)) {
            $operation['parameters'] = $parameters;
        }

        if ($body = $this->requests->requestBody($route)) {
            $operation['requestBody'] = $body;
        }

        $operation['responses'] = $this->responses->responses($route);
        $operation['security'] = $this->reader->security($route);

        // Which scopes the credential needs is the question integrators
        // actually hit, and it is enforced by middleware rather than stated
        // anywhere a reader would find it.
        if ($scopes = $this->reader->scopes($route)) {
            $operation['description'] = 'Requires scope: '.implode(', ', $scopes);
        }

        return $operation;
    }

    /**
     * Group by the domain the controller lives in, so the rendered page is
     * organised the way the codebase is.
     */
    private function tag(Route $route): string
    {
        if (preg_match('/App\\\\Domains\\\\([A-Za-z]+)\\\\/', (string) $route->getActionName(), $m) === 1) {
            return $m[1];
        }

        return 'Platform';
    }

    /**
     * A name unique across the document, as OpenAPI requires.
     *
     * Controller and method alone is not unique: twenty-three controller
     * methods are routed on both the session surface and the licence surface,
     * so `complianceController.submit` names two operations. The surface is
     * part of the identity, and prefixing it keeps the id readable where
     * folding the whole path into the name would not.
     */
    private function operationId(Route $route, string $method): string
    {
        $prefix = str_starts_with($route->uri(), 'api/v1/') ? 'v1.' : '';
        $action = $route->getActionName();

        // apiResource registers update under both PUT and PATCH, one method
        // serving two verbs on one path, so the verb joins the identity too.
        $verbs = array_diff($route->methods(), ['HEAD', 'OPTIONS']);
        $suffix = count($verbs) > 1 ? '.'.strtolower($method) : '';

        if (str_contains($action, '@')) {
            [$class, $function] = explode('@', $action);

            return $prefix.lcfirst(class_basename($class)).'.'.$function.$suffix;
        }

        return strtolower($method).Str::studly(str_replace(['/', '{', '}', '?'], ['-', '', '', ''], $route->uri()));
    }

    private function summary(Route $route): string
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return 'Closure route';
        }

        [$class, $function] = explode('@', $action);

        return Str::headline($function).' — '.Str::headline(class_basename($class));
    }
}
