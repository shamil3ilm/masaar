<?php

declare(strict_types=1);

/**
 * A busy store against a running server: many tills, one organization, each
 * issuing simplified invoices at a steady rate through the pipeline API.
 *
 * Not part of any suite and never run in CI. It measures, it does not assert:
 * how many invoices a second the server issued, how long each took, what it
 * refused, and whether every till's chain came out unbroken (counters one
 * apart, each document naming its predecessor's hash).
 *
 * Three steps, against the database the server uses:
 *
 *     php scripts/load/tills.php seed   [--tills=10]               > load.json
 *     php artisan serve --port=8123   (in another terminal, same database)
 *     php scripts/load/tills.php run    --plan=load.json --url=http://127.0.0.1:8123 [--rate=5] [--seconds=30]
 *     php scripts/load/tills.php verify --plan=load.json
 *
 * The database comes from the environment, as for the server: point both at
 * a database you can throw away (seed adds an organization, its tills and a
 * licence; it deletes nothing). Invoices are issued without filing
 * (auto_submit false), so nothing is sent to the tax authority.
 */

use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\SigningCredentials;

$root = dirname(__DIR__, 2);
require "$root/vendor/autoload.php";

$step = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $argument, $m)) {
        $options[$m[1]] = $m[2];
    }
}

$app = require "$root/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();

match ($step) {
    'seed' => seed((int) ($options['tills'] ?? 10)),
    'run' => run(plan($options), (string) ($options['url'] ?? 'http://127.0.0.1:8123'), (float) ($options['rate'] ?? 5), (int) ($options['seconds'] ?? 30)),
    'verify' => verify(plan($options)),
    default => fwrite(STDERR, "usage: php scripts/load/tills.php seed|run|verify [options] (see the comment at the top)\n") && exit(2),
};

/** An organization with its tills, signing credentials and an API licence; the plan on stdout. */
function seed(int $tills): void
{
    $organization = Organization::create([
        'name' => 'Load Test Trading '.date('His'), 'country' => 'SA', 'vat_number' => '399999999900003',
        'cr_number' => '1010010000', 'street' => 'King Fahd Road', 'building_number' => '1234',
        'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
    ]);

    // Self-signed on ZATCA's curve: issuing signs, and nothing here is filed.
    $credentials = credentials();
    app(CredentialStore::class)->put((string) $organization->id, null, CredentialStore::PCSID, [
        'privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'load-test',
    ]);

    $branches = [];
    for ($i = 1; $i <= $tills; $i++) {
        $branches[] = (string) app(BranchService::class)->create($organization, [
            'name' => "Till {$i}", 'city' => 'Riyadh', 'street' => 'King Fahd Road',
            'building_number' => '1234', 'district' => 'Al Olaya', 'postal_code' => '12345',
        ])->id;

        // A till of its own: its own credentials, so its own chain and lock.
        // Without them a branch signs as head office, and every till waits
        // on that one device.
        $own = credentials();
        app(CredentialStore::class)->put((string) $organization->id, end($branches), CredentialStore::PCSID, [
            'privateKey' => $own['privateKey'], 'pcsid' => $own['certificate'], 'secret' => 'load-test',
        ]);
    }

    $licence = License::createWithCredentials([
        'org_id' => $organization->id, 'organization_name' => $organization->name,
        'contact_email' => 'load@example.test', 'tier' => 'enterprise',
    ]);

    echo json_encode([
        'org_id' => (string) $organization->id,
        'branches' => $branches,
        'api_key' => $licence['api_key'],
        'api_secret' => $licence['api_secret'],
    ], JSON_PRETTY_PRINT)."\n";
}

/**
 * Each till sends at its rate for the duration, without waiting for the
 * previous answer, as a till with a queue of customers does.
 *
 * @param  array{org_id: string, branches: list<string>, api_key: string, api_secret: string}  $plan
 */
function run(array $plan, string $url, float $rate, int $seconds): void
{
    $multi = new CurlMultiHandler;
    $client = new Client(['base_uri' => $url, 'http_errors' => false, 'timeout' => 60, 'handler' => HandlerStack::create($multi)]);
    $interval = 1.0 / $rate;
    $start = microtime(true);
    $next = array_fill_keys($plan['branches'], $start);
    $pending = [];
    $results = [];
    $errors = [];
    $sequence = 0;

    while (microtime(true) - $start < $seconds) {
        foreach ($plan['branches'] as $branchId) {
            while ($next[$branchId] <= microtime(true)) {
                $sequence++;
                $sentAt = microtime(true);
                $pending[] = $client->postAsync('/api/v1/pipeline/submit', [
                    'headers' => ['X-API-Key' => $plan['api_key'], 'X-API-Secret' => $plan['api_secret'], 'Accept' => 'application/json'],
                    'json' => [
                        'org_id' => $plan['org_id'], 'branch_id' => $branchId, 'auto_submit' => false,
                        'invoice_number' => 'LOAD-'.substr($branchId, 0, 8).'-'.$sequence, 'type' => 'simplified',
                        'document_type' => 'invoice', 'issue_date' => date('Y-m-d'),
                        'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
                    ],
                ])->then(function ($response) use (&$results, &$errors, $sentAt): void {
                    $results[] = [$response->getStatusCode(), microtime(true) - $sentAt];

                    if ($response->getStatusCode() >= 500) {
                        $errors[] = substr((string) $response->getBody(), 0, 300);
                    }
                });
                $next[$branchId] += $interval;
            }
        }

        // Let the transfers progress, and collect what has finished.
        $multi->tick();
        usleep(2000);
    }

    Utils::settle($pending)->wait();
    $elapsed = microtime(true) - $start;

    $latencies = array_map(fn (array $r): float => $r[1], $results);
    sort($latencies);
    $statuses = array_count_values(array_map(fn (array $r): string => (string) $r[0], $results));
    $issued = ($statuses['201'] ?? 0) + ($statuses['200'] ?? 0);
    $percentile = fn (float $p): float => $latencies === [] ? 0.0 : $latencies[(int) min(count($latencies) - 1, floor($p * count($latencies)))];

    printf("tills %d, %.1f req/s each, %ds offered: %d requests in %.1fs\n", count($plan['branches']), $rate, $seconds, count($results), $elapsed);
    printf("issued %d (%.1f/s); statuses %s\n", $issued, $issued / $elapsed, json_encode($statuses));
    foreach (array_slice(array_unique($errors), 0, 3) as $error) {
        printf("server error: %s\n", $error);
    }
    printf("latency ms: p50 %.0f, p95 %.0f, p99 %.0f, max %.0f\n", $percentile(0.5) * 1000, $percentile(0.95) * 1000, $percentile(0.99) * 1000, ($latencies === [] ? 0 : end($latencies)) * 1000);
}

/**
 * Every chain the run signed on - a till's own, or head office's for a till
 * with no credentials of its own: counters 1..n with no gap or repeat, each document's
 * previous hash its predecessor's hash.
 *
 * @param  array{org_id: string, branches: list<string>}  $plan
 */
function verify(array $plan): void
{
    $broken = 0;

    $devices = Invoice::acrossTenants()->where('org_id', $plan['org_id'])->whereNotNull('hash')->distinct()->pluck('device_id');

    foreach ($devices as $branchId) {
        $chain = DB::table('hash_chain_history')
            ->where('org_id', $plan['org_id'])
            ->where('device_id', $branchId)
            ->orderBy('icv')
            ->get(['icv', 'invoice_hash', 'previous_hash']);
        $issued = Invoice::acrossTenants()->where('org_id', $plan['org_id'])->where('device_id', $branchId)->whereNotNull('hash')->count();
        $problems = [];

        foreach ($chain as $i => $entry) {
            if ((int) $entry->icv !== $i + 1) {
                $problems[] = "counter {$entry->icv} at position ".($i + 1);
            }
            if ($i > 0 && $entry->previous_hash !== $chain[$i - 1]->invoice_hash) {
                $problems[] = "counter {$entry->icv} does not name its predecessor";
            }
        }

        if (count($chain) !== $issued) {
            $problems[] = count($chain)." chain entries for {$issued} issued invoices";
        }

        $broken += $problems === [] ? 0 : 1;
        printf("till %s: %d documents, %s\n", substr($branchId, 0, 8), count($chain), $problems === [] ? 'chain intact' : implode('; ', array_slice($problems, 0, 3)));
    }

    printf("%s\n", $broken === 0 ? 'every chain intact' : "{$broken} chain(s) broken");
    exit($broken === 0 ? 0 : 1);
}

/** @return array<string, mixed> */
function plan(array $options): array
{
    $path = (string) ($options['plan'] ?? '');
    $plan = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

    if (! is_array($plan) || ! isset($plan['org_id'], $plan['branches'])) {
        fwrite(STDERR, "--plan must name the JSON seed printed\n");
        exit(2);
    }

    return $plan;
}

/**
 * Self-signed on ZATCA's curve, from the test fixture that knows where
 * OpenSSL's configuration is on each platform.
 *
 * @return array{privateKey: string, certificate: string}
 */
function credentials(): array
{
    return (new class
    {
        use SigningCredentials;

        /** What the fixture asserts in a test, a refusal here. */
        public function assertNotFalse(mixed $value, string $message = ''): void
        {
            if ($value === false) {
                throw new RuntimeException($message !== '' ? $message : 'OpenSSL could not make the credentials.');
            }
        }

        /** @return array{privateKey: string, certificate: string} */
        public function make(): array
        {
            return $this->selfSignedCredentials();
        }
    })->make();
}
