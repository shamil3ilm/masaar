<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\Audit\Services\AuditService;
use App\Support\LogSanitizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Every change a platform admin makes is on the record.
 *
 * Licences kept their own trail; kill switches, settings, the offline queue
 * and webhook replays wrote to the application log at most, which rotates and
 * names nobody. Recording here rather than in each controller means a new
 * admin action is covered the day it is added - which is the property a
 * record kept for an auditor needs, and the one per-screen code loses first.
 *
 * Attempts are recorded as well as successes. A refused change says who
 * tried, which is as much a part of the record as who succeeded.
 *
 * A controller that knows more says so through the request: TARGET names the
 * record changed, CHANGES its values before and after.
 */
class AuditAdminAction
{
    /** [entity type, entity id] of the record the action changed. */
    public const TARGET = 'audit.target';

    /** [old values, new values], for an action that edits something. */
    public const CHANGES = 'audit.changes';

    /**
     * Input that is never written down, by key: passwords and passphrases,
     * secrets, tokens, keys of any kind, one-time codes, PINs, card and bank
     * account details, authorization headers, cookies and signatures. A PIN
     * only as a word of its own, so shipping is kept.
     */
    private const SECRET = '/pass(word|phrase|code)|secret|token|key|credential|otp|(^|[_-])pin($|[_-])|cvv|cvc|card|iban|account_?number|authori[sz]ation|bearer|cookie|signature/i';

    private const MAX_LENGTH = 500;

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $response = $next($request);

        // The change has happened by now. A failure to record it is reported
        // loudly but must not turn the admin's completed action into an error.
        try {
            $this->record($request, $response);
        } catch (Throwable $e) {
            Log::critical('An admin action could not be audited', [
                'route' => $request->route()?->getName(),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'exception' => $e,
            ]);
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $name = $request->route()?->getName();
        $action = (string) ($name ?? 'admin.'.$request->path());
        $parameters = $request->route()?->parameters() ?? [];

        // A controller that knows the record names it. Otherwise the route
        // does: admin.kill-switches.enable acted on a KillSwitch, and
        // admin.licences.suspend on the licence in its first parameter. The
        // admin API's routes have no names; their path is the action.
        [$entityType, $entityId] = $request->attributes->get(self::TARGET, [
            $name === null ? 'AdminApi' : Str::studly(Str::singular(explode('.', $action)[1] ?? 'admin')),
            reset($parameters) ?: null,
        ]);
        [$old, $new] = $request->attributes->get(self::CHANGES, [null, null]);

        // Masked here, before the entry is written and so before it is
        // chained: what an entry holds is what its hash covers.
        $this->audit->logAdminAction(
            action: $action,
            entityType: $entityType,
            entityId: $entityId === null ? null : (string) $entityId,
            oldValues: $old === null ? null : $this->redact($old),
            newValues: $new === null ? null : $this->redact($new),
            metadata: [
                'outcome' => $this->outcome($request, $response),
                'status' => $response->getStatusCode(),
                'parameters' => $parameters,
                'input' => $this->redact($request->except(['_token', '_method'])),
            ],
        );
    }

    /**
     * Succeeded, refused (the screen said no) or failed (the server did).
     */
    private function outcome(Request $request, Response $response): string
    {
        if ($response->getStatusCode() >= 500) {
            return 'failed';
        }

        if ($response->getStatusCode() >= 400) {
            return 'refused';
        }

        $session = $request->hasSession() ? $request->session() : null;

        return $session !== null && ($session->has('error') || $session->has('errors'))
            ? 'refused'
            : 'succeeded';
    }

    /** Fields that hold someone's message rather than a setting. */
    private const PROSE = ['body', 'notes'];

    /**
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    private function redact(array $input): array
    {
        $kept = [];

        foreach ($input as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET, $key) === 1) {
                $kept[$key] = '[redacted]';

                continue;
            }

            // A message's text - a ticket reply, an internal note - is the
            // customer's matter, not the audit's, and the audit is readable
            // by every auditor. Its length says something was written.
            if (in_array($key, self::PROSE, true) && is_string($value)) {
                $kept[$key] = '['.mb_strlen($value).' characters]';

                continue;
            }

            // An upload is recorded by name, not as the object.
            if ($value instanceof UploadedFile) {
                $kept[$key] = '[file '.$value->getClientOriginalName().']';

                continue;
            }

            $kept[$key] = match (true) {
                is_array($value) => $this->redact($value),
                // A secret by its shape - a card number, an IBAN, a bearer
                // token, a private key - pasted into a field of any name.
                is_string($value) => Str::limit(LogSanitizer::sanitizeString($value), self::MAX_LENGTH),
                default => $value,
            };
        }

        return $kept;
    }
}
