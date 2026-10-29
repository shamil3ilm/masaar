<?php

declare(strict_types=1);

namespace App\Domains\Platform\Developers;

use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Support\CountryClock;

/**
 * The developer page's quick start: submit an invoice, follow its status,
 * and verify a webhook, each in curl, PHP, JavaScript and Python.
 *
 * Written here rather than as text, so the invoice every sample sends is the
 * one each country's module offers (Website::apiExample) and the paths are
 * the routes' own. DocumentedExamplesTest sends each country's invoice
 * through the pipeline, checks every sample carries it unchanged and calls
 * paths that exist, and checks the verification samples against the
 * signature the platform actually computes - so the page cannot drift from
 * the API it teaches.
 */
final class QuickStart
{
    public const SUBMIT = '/api/v1/pipeline/submit';

    /** What the samples send as org_id: the reader puts their own in its place. */
    public const ORGANIZATION_ID = 'your-organization-id';

    public const STATUS = '/api/v1/pipeline/status/';

    /** @var array<string, string> sample key => the language's name */
    public const LANGUAGES = ['curl' => 'curl', 'php' => 'PHP', 'javascript' => 'JavaScript', 'python' => 'Python'];

    public function __construct(
        private readonly JurisdictionRegistry $registry,
    ) {}

    /**
     * Each country this deployment issues in - or every one it has, when
     * none is switched on yet - with the invoice its quick start submits.
     *
     * @return array<string, array{name: string, invoice: array<string, mixed>, samples: array<string, string>}> by country code
     */
    public function submissions(): array
    {
        $codes = $this->registry->enabled() ?: $this->registry->registered();
        $examples = [];

        foreach ($codes as $code) {
            $jurisdiction = $this->registry->get($code);
            // The pipeline is told which organization the invoice is for, so a
            // sample copied as it stands is refused without it.
            $invoice = ['org_id' => self::ORGANIZATION_ID, ...$jurisdiction->website()->apiExample(CountryClock::now($code)->toDateString())];

            $examples[$code] = [
                'name' => $jurisdiction->name(),
                'invoice' => $invoice,
                'samples' => $this->submit($invoice),
            ];
        }

        return $examples;
    }

    /**
     * Submitting one invoice, in each language.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, string> language => code
     */
    public function submit(array $invoice): array
    {
        $json = self::json($invoice);
        $url = $this->base().self::SUBMIT;

        return [
            'curl' => <<<CURL
                curl -X POST {$url} \\
                  -H "X-API-Key: \$API_KEY" \\
                  -H "X-API-Secret: \$API_SECRET" \\
                  -H "Idempotency-Key: {$invoice['invoice_number']}" \\
                  -H "Content-Type: application/json" \\
                  --data-binary @- <<'JSON'
                {$json}
                JSON
                CURL,
            'php' => <<<PHP
                <?php
                \$invoice = json_decode(<<<'JSON'
                {$json}
                JSON, true);

                \$ch = curl_init('{$url}');
                curl_setopt_array(\$ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'X-API-Key: '.getenv('API_KEY'),
                        'X-API-Secret: '.getenv('API_SECRET'),
                        'Idempotency-Key: '.\$invoice['invoice_number'],
                        'Content-Type: application/json',
                    ],
                    CURLOPT_POSTFIELDS => json_encode(\$invoice),
                ]);
                \$answer = json_decode((string) curl_exec(\$ch), true);

                echo \$answer['data']['invoice_id'] ?? \$answer['error']['code'];
                PHP,
            'javascript' => <<<JS
                const invoice = {$json};

                const response = await fetch('{$url}', {
                  method: 'POST',
                  headers: {
                    'X-API-Key': process.env.API_KEY,
                    'X-API-Secret': process.env.API_SECRET,
                    'Idempotency-Key': invoice.invoice_number,
                    'Content-Type': 'application/json',
                  },
                  body: JSON.stringify(invoice),
                });
                const answer = await response.json();

                console.log(answer.data?.invoice_id ?? answer.error?.code);
                JS,
            'python' => <<<PY
                import json
                import os

                import requests

                invoice = json.loads(r'''{$json}''')

                response = requests.post(
                    '{$url}',
                    headers={
                        'X-API-Key': os.environ['API_KEY'],
                        'X-API-Secret': os.environ['API_SECRET'],
                        'Idempotency-Key': invoice['invoice_number'],
                    },
                    json=invoice,
                    timeout=30,
                )
                answer = response.json()

                print(answer.get('data', {}).get('invoice_id') or answer['error']['code'])
                PY,
        ];
    }

    /**
     * Where an invoice stands, in each language: issued, filed, accepted or
     * refused, and the authority's answer.
     *
     * @return array<string, string> language => code
     */
    public function status(): array
    {
        $url = $this->base().self::STATUS;

        return [
            'curl' => <<<CURL
                curl {$url}\$INVOICE_ID \\
                  -H "X-API-Key: \$API_KEY" \\
                  -H "X-API-Secret: \$API_SECRET"
                CURL,
            'php' => <<<PHP
                <?php
                \$ch = curl_init('{$url}'.\$invoiceId);
                curl_setopt_array(\$ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['X-API-Key: '.getenv('API_KEY'), 'X-API-Secret: '.getenv('API_SECRET')],
                ]);
                \$status = json_decode((string) curl_exec(\$ch), true)['data'];
                PHP,
            'javascript' => <<<JS
                const response = await fetch('{$url}' + invoiceId, {
                  headers: { 'X-API-Key': process.env.API_KEY, 'X-API-Secret': process.env.API_SECRET },
                });
                const status = (await response.json()).data;
                JS,
            'python' => <<<PY
                status = requests.get(
                    '{$url}' + invoice_id,
                    headers={'X-API-Key': os.environ['API_KEY'], 'X-API-Secret': os.environ['API_SECRET']},
                    timeout=30,
                ).json()['data']
                PY,
        ];
    }

    /**
     * Checking a webhook delivery is ours, in each language: the
     * X-Webhook-Signature header is "sha256=" and the lowercase hex
     * HMAC-SHA256 of the raw body, keyed with the endpoint's secret
     * (WebhookService::generateSignature) - two of them, comma-separated,
     * while a rotation overlaps, of which one is enough. The body's signed
     * timestamp must be within five minutes, so a delivery captured and
     * sent again later is refused.
     *
     * @return array<string, string> language => code
     */
    public function webhook(): array
    {
        return [
            'curl' => <<<'SH'
                # Compute the signature of a delivery you saved, to compare with its header:
                printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" | sed 's/^.* /sha256=/'
                SH,
            'php' => <<<'PHP'
                <?php
                function verify(string $rawBody, string $header, string $secret, int $tolerance = 300): bool
                {
                    $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
                    $signed = false;
                    foreach (explode(',', $header) as $given) {
                        $signed = hash_equals($expected, trim($given)) || $signed;
                    }
                    $sent = $signed ? strtotime((string) (json_decode($rawBody, true)['timestamp'] ?? '')) : false;

                    return $sent !== false && abs(time() - $sent) <= $tolerance;
                }

                $body = file_get_contents('php://input');
                if (! verify($body, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '', getenv('WEBHOOK_SECRET'))) {
                    http_response_code(401);
                    exit;
                }
                PHP,
            'javascript' => <<<'JS'
                import { createHmac, timingSafeEqual } from 'node:crypto';

                // rawBody is a Buffer: in Express, use express.raw({ type: 'application/json' }).
                function verify(rawBody, header, secret, toleranceSeconds = 300) {
                  const expected = Buffer.from('sha256=' + createHmac('sha256', secret).update(rawBody).digest('hex'));
                  const signed = (header ?? '').split(',').some((given) => {
                    const candidate = Buffer.from(given.trim());
                    return candidate.length === expected.length && timingSafeEqual(candidate, expected);
                  });
                  if (!signed) return false;
                  const sent = Date.parse(JSON.parse(rawBody.toString('utf8')).timestamp);
                  return Math.abs(Date.now() - sent) <= toleranceSeconds * 1000;
                }
                JS,
            'python' => <<<'PY'
                import hashlib
                import hmac
                import json
                from datetime import datetime, timezone

                def verify(raw_body: bytes, header: str | None, secret: str, tolerance: int = 300) -> bool:
                    expected = "sha256=" + hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
                    if not any(hmac.compare_digest(expected, given.strip()) for given in (header or "").split(",")):
                        return False
                    sent = datetime.fromisoformat(json.loads(raw_body)["timestamp"].replace("Z", "+00:00"))
                    return abs((datetime.now(timezone.utc) - sent).total_seconds()) <= tolerance
                PY,
        ];
    }

    /**
     * The invoice as the samples carry it.
     *
     * @param  array<string, mixed>  $invoice
     */
    public static function json(array $invoice): string
    {
        return (string) json_encode($invoice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
