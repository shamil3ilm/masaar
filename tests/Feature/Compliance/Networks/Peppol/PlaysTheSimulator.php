<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Compliance\Networks\Peppol\Simulator\PlaySimulatedMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * The service-provider simulator as the gateway, with the network played
 * on demand.
 *
 * Its callbacks are real HTTP requests to the callback URL; here they are
 * caught and handed to this application unchanged - the same raw body and
 * signature headers - so the routes, the signature check and the
 * controllers are the production ones, and the simulator sees the answer
 * the application gave. Its queued callbacks wait until playNetwork(),
 * which stands in for time passing.
 */
trait PlaysTheSimulator
{
    private const CALLBACK_URL = 'http://simulator.masaar.test';

    /** @var list<int> HTTP status of every callback, in order */
    private array $callbackAnswers = [];

    /** How many of the queued callbacks have been run. */
    private int $playedJobs = 0;

    private function useSimulator(): void
    {
        config([
            'peppol.gateway' => 'simulator',
            'peppol.simulator.step_seconds' => 0,
            'peppol.simulator.callback_url' => self::CALLBACK_URL,
        ]);

        Queue::fake([PlaySimulatedMessage::class]);
        Http::fake([self::CALLBACK_URL.'/*' => fn (Request $request): mixed => $this->relay($request)]);
    }

    /**
     * Run every queued callback, and those they queue, until none is left.
     */
    private function playNetwork(): void
    {
        while ($this->playNext()) {
            // Each run may queue the next callback, or a retry.
        }
    }

    /**
     * Run the oldest queued callback not run yet.
     *
     * @return bool whether there was one
     */
    private function playNext(): bool
    {
        $job = Queue::pushed(PlaySimulatedMessage::class)->values()->get($this->playedJobs);

        if ($job === null) {
            return false;
        }

        $this->playedJobs++;
        app()->call([$job, 'handle']);

        return true;
    }

    /**
     * @return list<array<string, mixed>> the bodies of the callbacks to $path, in order
     */
    private function callbacksTo(string $path): array
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), $path))
            ->map(fn (array $pair): array => (array) json_decode($pair[0]->body(), true))
            ->values()
            ->all();
    }

    private function relay(Request $request): mixed
    {
        $headers = [];

        foreach (['X-Signature', 'X-Signature-Timestamp', 'Content-Type', 'Accept'] as $name) {
            $headers[$name] = $request->header($name)[0] ?? '';
        }

        $response = $this->call(
            'POST',
            (string) parse_url($request->url(), PHP_URL_PATH),
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers),
            $request->body(),
        );

        $this->callbackAnswers[] = $response->getStatusCode();

        return Http::response((string) $response->getContent(), $response->getStatusCode());
    }
}
