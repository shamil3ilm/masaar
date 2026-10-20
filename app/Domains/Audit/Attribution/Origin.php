<?php

declare(strict_types=1);

namespace App\Domains\Audit\Attribution;

use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Who is acting, how they reached us, through which licence, and in which
 * request: what every record is stamped with (RecordsActors).
 *
 * Read from the request while there is one. A queued job has none, so what
 * the request knew is carried into the job with Laravel's context, hidden
 * from the log: a retry still knows who issued the invoice it files, while
 * what the job itself writes says it came from the platform (system).
 *
 * Identifiers only. A name or an email is never copied here - a person is
 * their user id, so renaming or deleting the account changes no record.
 */
class Origin
{
    /** The hidden context key the request's origin travels into a job under. */
    public const CONTEXT = 'origin';

    /** @var array<int, true> The queued jobs running in this process, by object. */
    private static array $jobs = [];

    /** The way in a piece of work said it is, while it runs (during()). */
    private static ?Channel $during = null;

    /**
     * Run work that knows how what it writes arrived - a file brought in is
     * an import, whether a request or a job reads it - and say so on
     * every record it creates.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function during(Channel $channel, callable $work): mixed
    {
        $previous = self::$during;
        self::$during = $channel;

        try {
            return $work();
        } finally {
            self::$during = $previous;
        }
    }

    /**
     * Carry the origin into every job dispatched, and know when one runs.
     * Called once, from the service provider.
     */
    public static function listen(): void
    {
        // A job dispatched from inside another keeps the first one's origin:
        // the person who started it all, not the job that passed it on.
        Context::dehydrating(function (ContextRepository $context): void {
            if (! $context->hasHidden(self::CONTEXT)) {
                $context->addHidden(self::CONTEXT, app(self::class)->snapshot());
            }
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            self::$jobs[spl_object_id($event->job)] = true;
        });

        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $finished) {
            Event::listen($finished, function (JobProcessed|JobFailed|JobExceptionOccurred $event): void {
                unset(self::$jobs[spl_object_id($event->job)]);
            });
        }
    }

    /**
     * The person acting: the one signed in, or the one whose request queued
     * this job. Null for the scheduler, a command, or an integration with no
     * person behind it.
     */
    public function actorId(): ?string
    {
        $id = auth()->id();

        return $id !== null ? (string) $id : $this->carried('actor_id');
    }

    /**
     * How what is being written arrived.
     */
    public function channel(): Channel
    {
        if (self::$during !== null) {
            return self::$during;
        }

        if (self::$jobs !== []) {
            return Channel::System;
        }

        return $this->routeChannel($this->request()) ?? Channel::System;
    }

    /**
     * The partner licence (API key) the request came in under, which says
     * which integration acted for the customer.
     */
    public function licenseId(): ?string
    {
        $license = $this->request()?->attributes->get('license');

        if (is_object($license) && isset($license->id)) {
            return (string) $license->id;
        }

        return $this->carried('license_id');
    }

    /**
     * The environment of the credential acting - 'sandbox' or 'production' -
     * carried into its jobs: the organization's own API key's, which
     * ValidateLicense puts beside the licence it acts under, or else the
     * licence's own. Null for a person or the platform.
     */
    public function credentialEnvironment(): ?string
    {
        $attributes = $this->request()?->attributes;

        foreach (['api_key', 'license'] as $credential) {
            $held = $attributes?->get($credential);

            if (is_object($held) && isset($held->environment)) {
                return $held->environment instanceof \BackedEnum ? (string) $held->environment->value : (string) $held->environment;
            }
        }

        // A job queued before licences were held carries only its key's.
        return $this->carried('credential_environment') ?? $this->carried('api_key_environment');
    }

    /**
     * The request's correlation id (LogContext), carried into its jobs, so
     * one request can be followed from the call to its filing.
     */
    public function requestId(): ?string
    {
        $carried = $this->carried('request_id');

        if ($carried !== null) {
            return $carried;
        }

        $header = (string) $this->request()?->headers->get('X-Request-Id', '');

        return $header === '' ? null : $header;
    }

    /**
     * What a write made with the query builder - which no model event sees -
     * stamps itself with, by column.
     *
     * @param  list<string>  $columns  any of created_by, updated_by, created_via, license_id, request_id
     * @return array<string, string|null>
     */
    public function columns(array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $values[$column] = match ($column) {
                'created_by', 'updated_by', 'deleted_by' => $this->actorId(),
                'created_via' => $this->channel()->value,
                'license_id' => $this->licenseId(),
                'request_id' => $this->requestId(),
                default => throw new \InvalidArgumentException("{$column} is not an attribution column."),
            };
        }

        return $values;
    }

    /**
     * What a job dispatched now carries.
     *
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        return [
            'actor_id' => $this->actorId(),
            'channel' => $this->channel()->value,
            'license_id' => $this->licenseId(),
            'request_id' => $this->requestId(),
            'credential_environment' => $this->credentialEnvironment(),
        ];
    }

    /**
     * The channel the route a request matched belongs to: the partner API
     * (licence key) and its pipeline, the operators' console and API, the
     * person-token API, or the portal.
     */
    private function routeChannel(?Request $request): ?Channel
    {
        $route = $request?->route();

        if ($route === null || ! is_object($route)) {
            return null;
        }

        $middleware = $route->gatherMiddleware();
        $uri = $route->uri();

        return match (true) {
            in_array('license', $middleware, true) && str_contains($uri, 'pipeline/batch') => Channel::Batch,
            in_array('license', $middleware, true) && str_contains($uri, 'pipeline/') => Channel::Pipeline,
            in_array('license', $middleware, true) => Channel::Api,
            in_array('platform.admin', $middleware, true) => Channel::Admin,
            str_starts_with($uri, 'api/') || str_starts_with($uri, 'v1/') => Channel::Api,
            str_starts_with((string) $route->getName(), 'admin.') => Channel::Admin,
            default => Channel::Portal,
        };
    }

    private function carried(string $key): ?string
    {
        try {
            $origin = Context::getHidden(self::CONTEXT);
        } catch (\Throwable $e) {
            Log::debug('Origin context unreadable', ['error' => $e->getMessage()]);

            return null;
        }

        $value = is_array($origin) ? ($origin[$key] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function request(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
