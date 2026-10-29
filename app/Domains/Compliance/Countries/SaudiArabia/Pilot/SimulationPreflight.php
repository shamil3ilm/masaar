<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Pilot;

use App\Domains\Audit\Services\AuditChain;
use App\Domains\Audit\Services\ImmutabilityTriggers;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\UnfiledDocuments;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\SellerIdentification;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Countries\SaudiArabia\Support\TextNormalizer;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Services\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Throwable;

/**
 * What has to hold before a platform admin moves a real organization to
 * ZATCA's simulation environment, each with what to do when it does not.
 *
 * Read-only: nothing is written to the database, the cache, the circuit
 * breaker or the credential store. The simulation gateway is looked at
 * as Connectivity judges the default one (Connectivity::probe()), and its
 * Date header is the time reference, so no other service is called. A
 * test mail is sent only when asked for.
 */
class SimulationPreflight
{
    /** How old the scheduler's or a worker's last beat may be. */
    public const HEARTBEAT_MINUTES = 5;

    /** How far this server's clock may be from the gateway's. */
    public const CLOCK_SECONDS = 5;

    /** Transports that deliver nothing to anyone. */
    private const NO_DELIVERY = ['log', 'array'];

    public function __construct(
        private readonly UnfiledDocuments $unfiled,
        private readonly Connectivity $connectivity,
        private readonly Heartbeat $heartbeat,
        private readonly CredentialStore $credentials,
        private readonly MailFactory $mail,
        private readonly ImmutabilityTriggers $triggers,
    ) {}

    /**
     * @param  string|null  $testMailTo  an address to send a test message to
     * @return list<Check>
     */
    public function run(Organization $organization, ?string $testMailTo = null): array
    {
        return [
            $this->approved($organization),
            $this->vatNumber($organization),
            $this->sellerId($organization),
            $this->address($organization),
            $this->arabicName($organization),
            $this->branches($organization),
            $this->nothingUnfiled($organization),
            $this->mailer(),
            ...($testMailTo === null ? [] : [$this->testMail($testMailTo)]),
            $this->queues(),
            $this->scheduler(),
            ...$this->gateway(),
            $this->storage(),
            $this->tls(),
            $this->credentialKey(),
            $this->auditKey(),
            $this->databaseTriggers(),
            $this->databaseRights(),
            $this->production(),
        ];
    }

    private function approved(Organization $organization): Check
    {
        $name = __('Organization approved');

        return $organization->status === Organization::STATUS_ACTIVE
            ? Check::pass('approved', $name, __('Active.'))
            : Check::fail('approved', $name, __('It is :status.', ['status' => $organization->status]), __('Approve it, or lift its suspension, on its page in the console.'));
    }

    private function vatNumber(Organization $organization): Check
    {
        $name = __('VAT number');

        return $organization->isValidVatNumber()
            ? Check::pass('vat', $name, (string) $organization->vat_number)
            : Check::fail('vat', $name, __('":vat" is not 15 digits starting and ending with 3.', ['vat' => (string) $organization->vat_number]), __('Enter the VAT number exactly as on its VAT registration.'));
    }

    /**
     * BR-KSA-08: the seller is identified (BT-29) by a scheme the rule
     * allows - CRN, MOM, MLS, 700, SAG or OTH - judged as issuing judges
     * it (SellerIdentification).
     */
    private function sellerId(Organization $organization): Check
    {
        $name = __('Seller identification');
        $scheme = SellerIdentification::scheme($organization);
        $problem = SellerIdentification::problem($scheme, (string) $organization->cr_number);

        return $problem === null
            ? Check::pass('seller_id', $name, $scheme.' '.$organization->cr_number)
            : Check::fail('seller_id', $name, $scheme.': '.$problem, __('Enter its seller identification and its type on the company page (BR-KSA-08).'));
    }

    /** BR-KSA-09, BR-KSA-37, BR-KSA-66: the seller's national address, complete. */
    private function address(Organization $organization): Check
    {
        $missing = array_keys(array_filter([
            __('street') => blank($organization->street),
            __('building number (4 digits)') => preg_match('/^\d{4}$/', (string) $organization->building_number) !== 1,
            __('district') => blank($organization->district),
            __('city') => blank($organization->city),
            __('postal code (5 digits)') => preg_match('/^\d{5}$/', (string) $organization->postal_code) !== 1,
        ]));

        return $missing === []
            ? Check::pass('address', __('National address'), __('Complete.'))
            : Check::fail('address', __('National address'), __('Missing or invalid: :fields.', ['fields' => implode(', ', $missing)]), __('Complete the national address on the company page.'));
    }

    private function arabicName(Organization $organization): Check
    {
        $name = __('Arabic legal name');

        return filled($organization->name_ar) && TextNormalizer::containsArabic((string) $organization->name_ar)
            ? Check::pass('arabic_name', $name, (string) $organization->name_ar)
            : Check::fail('arabic_name', $name, __('None in Arabic letters.'), __('Enter the Arabic legal name exactly as on the commercial registration.'));
    }

    /**
     * A switch archives the certificates of the environment it leaves, so
     * a document they signed and ZATCA does not hold yet could no longer be
     * filed.
     */
    private function nothingUnfiled(Organization $organization): Check
    {
        $name = __('Nothing waiting to be filed');
        $waiting = array_sum(array_map('array_sum', $this->unfiled->countsFor((string) $organization->id)));
        $environment = FatooraEnvironment::label(FatooraEnvironment::of($organization));

        return $waiting === 0
            ? Check::pass('unfiled', $name, __('Nothing waits in :environment.', ['environment' => $environment]))
            : Check::fail('unfiled', $name, __(':count documents issued in :environment are not filed yet.', ['count' => $waiting, 'environment' => $environment]),
                __('Let them be filed first (the offline queue, or a retry from the submissions page): switching archives the certificate they need.'));
    }

    private function mailer(): Check
    {
        $name = __('Outgoing mail');
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport");

        return $transport !== '' && ! in_array($transport, self::NO_DELIVERY, true)
            ? Check::pass('mail', $name, __('Mailer :mailer (:transport).', ['mailer' => $mailer, 'transport' => $transport]))
            : Check::fail('mail', $name, __('Mailer :mailer delivers nothing (:transport).', ['mailer' => $mailer, 'transport' => $transport !== '' ? $transport : '-']),
                __('Set MAIL_MAILER and its settings to a real mail service: sign-up, invitations and notices go by mail.'));
    }

    private function testMail(string $to): Check
    {
        $name = __('Test message');

        try {
            $this->mail->mailer()->raw(
                __('This is a test message from :app, sent before an organization moves to ZATCA\'s simulation environment. Nothing needs doing.', ['app' => config('app.name')]),
                fn ($message) => $message->to($to)->subject(__(':app: test message', ['app' => config('app.name')])),
            );
        } catch (Throwable $e) {
            return Check::fail('mail_send', $name, $e->getMessage(), __('Check the mail settings and that the mail service accepts this server.'));
        }

        return Check::pass('mail_send', $name, __('Sent to :address: check that it arrived.', ['address' => $to]));
    }

    /**
     * The queues filing and its notices go through. A worker records a beat
     * when it picks up the one platform:heartbeat sends every minute.
     */
    private function queues(): Check
    {
        $name = __('Queue workers');

        if (config('queue.default') === 'sync') {
            return Check::fail('queues', $name, __('QUEUE_CONNECTION is sync: jobs run inside the request, with no workers.'), __('Set QUEUE_CONNECTION to a real queue, such as database, with workers running.'));
        }

        $queues = array_values(array_unique(array_filter([
            'default',
            (string) config('fatoora.queue.name', 'zatca-submissions'),
            (string) config('fatoora.queue.webhooks_queue', 'webhooks'),
        ])));

        $quiet = array_values(array_filter($queues, fn (string $queue): bool => $this->stale($this->heartbeat->last(Heartbeat::queue($queue)))));

        return $quiet === []
            ? Check::pass('queues', $name, __('Worked within :minutes minutes: :queues.', ['minutes' => self::HEARTBEAT_MINUTES, 'queues' => implode(', ', $queues)]))
            : Check::fail('queues', $name, __('No worker has picked up a job lately on: :queues.', ['queues' => implode(', ', $quiet)]),
                __('Start the queue workers, and check that the scheduler runs (it sends the heartbeat they answer).'));
    }

    private function scheduler(): Check
    {
        $name = __('Scheduler');
        $last = $this->heartbeat->last(Heartbeat::SCHEDULER);

        return ! $this->stale($last)
            ? Check::pass('scheduler', $name, __('Last ran :time.', ['time' => $last?->toIso8601String()]))
            : Check::fail('scheduler', $name, $last === null ? __('No run recorded.') : __('Last ran :time.', ['time' => $last->toIso8601String()]),
                __('Run schedule:run every minute: it drains the offline queue and sends the heartbeat.'));
    }

    /**
     * The simulation gateway, judged reachable as the platform judges its
     * gateway before filing, and this server's clock against its Date
     * header: ZATCA refuses a document whose time is off (TimestampValidator).
     *
     * @return array{0: Check, 1: Check}
     */
    private function gateway(): array
    {
        $before = microtime(true);
        $probe = $this->connectivity->reach(FatooraEnvironment::baseUrl(FatooraEnvironment::SIMULATION));
        $after = microtime(true);

        $reach = $probe['available']
            ? Check::pass('gateway', __('Simulation gateway reachable'), __('Answered in :ms ms.', ['ms' => (int) $probe['latency_ms']]))
            : Check::fail('gateway', __('Simulation gateway reachable'), (string) $probe['reason'],
                __('Open outbound HTTPS to the gateway; an unreachable gateway sends every invoice to the offline queue.'));

        return [$reach, $this->clock($probe['server_date'] ?? null, ($before + $after) / 2)];
    }

    private function clock(?string $serverDate, float $localMoment): Check
    {
        $name = __('Server clock');

        try {
            $server = $serverDate === null ? null : CarbonImmutable::parse($serverDate);
        } catch (Throwable) {
            $server = null;
        }

        if ($server === null) {
            return Check::fail('clock', $name, __('The gateway gave no time to compare with.'), __('Make the gateway reachable, then run this again.'));
        }

        // The header is whole seconds, so the gateway's moment is anywhere
        // in the second it names.
        $drift = abs($localMoment - ($server->getTimestamp() + 0.5));

        return $drift <= self::CLOCK_SECONDS
            ? Check::pass('clock', $name, __('Within :seconds seconds of the gateway.', ['seconds' => (int) ceil($drift)]))
            : Check::fail('clock', $name, __(':seconds seconds from the gateway.', ['seconds' => (int) round($drift)]), __('Turn on time synchronisation (NTP) on the server.'));
    }

    /** Where onboarding stores the certificates and keys it obtains. */
    private function storage(): Check
    {
        $name = __('Certificate storage writable');
        $fix = __('Give the application\'s user write access to it, on a disk that survives a redeploy.');

        try {
            $disk = $this->credentials->disk();
        } catch (Throwable $e) {
            return Check::fail('storage', $name, $e->getMessage(), $fix);
        }

        if (! $disk instanceof FilesystemAdapter || ! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return Check::pass('storage', $name, __('A remote disk: its writes are checked when onboarding stores the first certificate.'));
        }

        $path = rtrim($disk->path(''), '\\/');

        // The disk makes its folder on the first write: what has to be
        // writable is the nearest folder that exists.
        while (! file_exists($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }

        return is_dir($path) && is_writable($path)
            ? Check::pass('storage', $name, $disk->path(''))
            : Check::fail('storage', $name, __(':path is not writable.', ['path' => $disk->path('')]), $fix);
    }

    /**
     * Each active branch can be onboarded and named on a document: a device
     * serial for its CSR, and its own CR, when it has one, as BR-KSA-08
     * takes it (SellerParty uses a branch's own CR on its documents).
     */
    private function branches(Organization $organization): Check
    {
        $name = __('Branches and tills');
        $problems = [];

        foreach (Branch::acrossTenants()->where('org_id', $organization->id)->where('is_active', true)->orderBy('name')->get() as $branch) {
            if (blank($branch->device_serial)) {
                $problems[] = __(':branch has no device serial.', ['branch' => $branch->name]);
            }

            if (filled($branch->cr_number) && ($problem = SellerIdentification::problem(SellerIdentification::DEFAULT, (string) $branch->cr_number)) !== null) {
                $problems[] = $branch->name.': '.$problem;
            }
        }

        return $problems === []
            ? Check::pass('branches', $name, __('Every active branch has a device serial and a valid CR of its own, or none.'))
            : Check::fail('branches', $name, implode(' ', $problems), __('Give each branch a device serial, and its own commercial registration only if it has one, on its page.'));
    }

    /** The gateway's certificate is checked: off, any server can answer as ZATCA. */
    private function tls(): Check
    {
        $name = __('TLS certificate verification');

        return (bool) config('fatoora.ssl_verify', true)
            ? Check::pass('tls', $name, __('On.'))
            : Check::fail('tls', $name, __('ZATCA_SSL_VERIFY is off.'), __('Remove ZATCA_SSL_VERIFY=false: the certificate of the gateway must be checked.'));
    }

    /** Signing credentials under a key of their own (CredentialStore), never the application key. */
    private function credentialKey(): Check
    {
        $name = __('Signing credential key');
        $key = (string) config('fatoora.signing.key', '');

        return match (true) {
            $key === '' => Check::fail('credential_key', $name, __('ZATCA_CREDENTIAL_KEY is not set: the signing keys would be encrypted under the application key.'), __('Set ZATCA_CREDENTIAL_KEY to a key of its own before onboarding (see .env.example).')),
            $key === (string) config('app.key') => Check::fail('credential_key', $name, __('ZATCA_CREDENTIAL_KEY is the application key.'), __('Give ZATCA_CREDENTIAL_KEY a key of its own (see .env.example).')),
            default => Check::pass('credential_key', $name, __('A key of its own.')),
        };
    }

    /** The audit chain's seal (AuditChain::seal) has a key of its own. */
    private function auditKey(): Check
    {
        $name = __('Audit chain key');

        try {
            $keys = AuditChain::keys();
        } catch (Throwable $e) {
            return Check::fail('audit_key', $name, $e->getMessage(), __('Give AUDIT_CHAIN_KEY a key of its own (see .env.example).'));
        }

        return $keys !== []
            ? Check::pass('audit_key', $name, __('New audit entries are sealed.'))
            : Check::fail('audit_key', $name, __('AUDIT_CHAIN_KEY is not set: audit entries carry no keyed seal.'), __('Set AUDIT_CHAIN_KEY to a key of its own, kept out of the database (see .env.example).'));
    }

    /** The triggers that keep issued invoices and the audit log unchanged (ImmutabilityTriggers). */
    private function databaseTriggers(): Check
    {
        $name = __('Immutability triggers');
        $missing = $this->triggers->missing();

        return $missing === []
            ? Check::pass('triggers', $name, __('All :count are in place.', ['count' => count(ImmutabilityTriggers::EXPECTED)]))
            : Check::fail('triggers', $name, __('Missing: :triggers.', ['triggers' => implode(', ', $missing)]), __('Run the migrations on MySQL with an account that may create triggers (docs/operations/DEPLOYMENT.md).'));
    }

    /** The application's database account cannot drop the triggers or truncate a table. */
    private function databaseRights(): Check
    {
        $name = __('Database account rights');
        $excess = $this->triggers->excessRights();

        return $excess === []
            ? Check::pass('db_rights', $name, __('The application cannot drop triggers or truncate tables.'))
            : Check::fail('db_rights', $name, __('The database account the application uses holds: :rights.',['rights' => implode(', ', $excess)]),
                __('Serve with an account holding SELECT, INSERT, UPDATE and DELETE only, and migrate with DB_MIGRATION_USERNAME (docs/operations/DEPLOYMENT.md).'));
    }

    private function production(): Check
    {
        $name = __('Production settings');
        $environment = (string) config('app.env');
        $debug = (bool) config('app.debug');

        return $environment === 'production' && ! $debug
            ? Check::pass('app_env', $name, __('APP_ENV production, debug off.'))
            : Check::fail('app_env', $name, __('APP_ENV :env, debug :debug.', ['env' => $environment, 'debug' => $debug ? 'on' : 'off']), __('Set APP_ENV=production and APP_DEBUG=false.'));
    }

    private function stale(?CarbonImmutable $beat): bool
    {
        return $beat === null || $beat->lt(now()->subMinutes(self::HEARTBEAT_MINUTES));
    }
}
