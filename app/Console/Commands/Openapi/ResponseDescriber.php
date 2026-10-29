<?php

declare(strict_types=1);

namespace App\Console\Commands\Openapi;

use App\Http\Responses\ApiResponse;
use App\Http\Responses\ResponseData;
use App\Support\Errors\ErrorCode;
use Illuminate\Routing\Route;

/**
 * The response side of an operation, for platform:openapi: the envelopes
 * ApiResponse builds, and the answers a route gives, read from its method
 * and its middleware.
 */
final class ResponseDescriber
{
    /** The header an answer carries when it repeats an earlier one. */
    private const REPLAYED_HEADER = 'Idempotent-Replayed';

    public function __construct(private readonly RouteReader $reader) {}

    /**
     * The response envelopes, as App\Http\Responses\ApiResponse builds them.
     *
     * Taken from that class rather than composed here, so the description
     * stays a reading of the code. `data` is left untyped here; an
     * operation whose method declares its data (#[ResponseData]) writes the
     * envelope out with it.
     *
     * @return array<string, mixed>
     */
    public function envelopes(): array
    {
        return [
            'Success' => [
                'type' => 'object',
                'required' => ['success'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => true],
                    'message' => ['type' => 'string'],
                    'data' => ['description' => 'Endpoint-specific payload'],
                ],
            ],
            'Paginated' => [
                'type' => 'object',
                'required' => ['success', 'data', 'meta'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => true],
                    'message' => ['type' => 'string'],
                    'data' => ['type' => 'array', 'items' => ['description' => 'Endpoint-specific item']],
                    'meta' => [
                        'type' => 'object',
                        'required' => ['current_page', 'last_page', 'per_page', 'total'],
                        'properties' => [
                            'current_page' => ['type' => 'integer'],
                            'last_page' => ['type' => 'integer'],
                            'per_page' => ['type' => 'integer'],
                            'total' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            // Every key ApiResponse::error writes, and only those: closed, so
            // a key added to the envelope is undescribed until it is named
            // here (ErrorSchemaTest).
            // ApiResponse::cursor: a page walked by cursor (Keyset).
            'CursorPage' => [
                'type' => 'object',
                'required' => ['success', 'data', 'meta'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => true],
                    'data' => ['type' => 'array', 'items' => ['description' => 'Endpoint-specific item']],
                    'meta' => [
                        'type' => 'object',
                        'required' => ['per_page', 'next_cursor'],
                        'properties' => [
                            'per_page' => ['type' => 'integer'],
                            'next_cursor' => ['type' => ['string', 'null'], 'description' => 'Send as ?cursor= for the next page; null on the last'],
                        ],
                    ],
                ],
            ],
            // Every compliance and idempotency code, as an enum a client can
            // switch on. Read off ErrorCode, so a code added there is here on
            // the next generation (OpenapiDriftTest).
            'ErrorCode' => [
                'type' => 'string',
                'enum' => array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases()),
                'description' => 'The compliance and idempotency codes; docs/integration/ERRORS.md says what each means and what to do',
            ],
            'Error' => [
                'type' => 'object',
                'required' => ['success', 'error'],
                'additionalProperties' => false,
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => false],
                    'error' => [
                        'type' => 'object',
                        'required' => ['code', 'message', 'category', 'retryable'],
                        'additionalProperties' => false,
                        'properties' => [
                            'code' => [
                                'anyOf' => [['$ref' => '#/components/schemas/ErrorCode'], ['type' => 'string']],
                                'description' => 'A compliance or idempotency code (ErrorCode: VAL_025, SYS_008, IDEMPOTENCY_KEY_REUSED ...), or a platform code named from the status (NOT_FOUND, VALIDATION_ERROR ...) or the refusal (LICENSE_INVALID_KEY ...): docs/integration/ERRORS.md',
                            ],
                            'message' => ['type' => 'string'],
                            'category' => ['type' => 'string', 'description' => 'Which vocabulary code is from, or the kind of failure: authentication, validation, rate_limit, system, request, license ...'],
                            'retryable' => ['type' => 'boolean', 'description' => 'True for 408, 429, 502, 503 and 504: the same request may succeed later'],
                            'retry_after' => ['type' => 'integer', 'description' => 'Seconds to wait before retrying, on a retryable error that says; also sent as Retry-After'],
                            'max_retries' => ['type' => 'integer', 'description' => 'How many times a retryable compliance error is worth retrying'],
                            'details' => ['type' => 'object', 'description' => 'Field errors (field => messages) for a 422, or the context the failure carries'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Only what the shape of the route establishes, and the data its
     * method declares (#[ResponseData]).
     *
     * @return array<string, mixed>
     */
    public function responses(Route $route): array
    {
        $body = $this->reader->methodBody($route);

        // Built from the class rather than written as a literal, so renaming
        // ApiResponse moves this with it instead of silently describing every
        // endpoint as returning the plain envelope.
        $factory = class_basename(ApiResponse::class);

        $created = str_contains($body, $factory.'::created(');
        $envelope = str_contains($body, $factory.'::paginated(') ? 'Paginated' : 'Success';
        $data = $this->reader->responseData($route);
        $schema = $this->envelope($envelope, $data);
        $success = $this->envelope('Success', $data?->list ? null : $data);

        // Page numbers, or a cursor when the request sends one (Keyset).
        if ($this->reader->walksByCursor($route)) {
            $schema = ['oneOf' => [$schema, $this->envelope('CursorPage', $data)]];
        }

        $status = $created ? '201' : '200';

        $responses = [
            $status => [
                'description' => $created ? 'Created' : 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => $schema,
                    ],
                ],
            ],
        ];

        // A retry under the same key answered with the first request's own
        // answer, status and all, by the idempotent middleware.
        if ($this->reader->idempotentRoute($route)) {
            $responses[$status]['headers'][self::REPLAYED_HEADER] = self::replayedHeader();
            $responses['409'] = $this->errorResponse('A request under the same Idempotency-Key is still being answered (IDEMPOTENCY_KEY_IN_PROGRESS); send it again shortly');
        }

        // A retry under the same key answered from what the first request
        // made, as the method says when it sets the header.
        if (str_contains($body, "'".self::REPLAYED_HEADER."'")) {
            $responses['200'] ??= [
                'description' => 'Replayed: the first request\'s result',
                'content' => ['application/json' => ['schema' => $success]],
            ];
            $responses['200']['headers'][self::REPLAYED_HEADER] = self::replayedHeader();
        }

        // The pipeline answers 207 when a step after drafting did not go
        // through: its own literal, or the outcome it shares with submit.
        if (preg_match('/\b207\b|->outcome\(/', $body) === 1) {
            $responses['207'] = [
                'description' => 'Partly done: a step after the first did not go through (for an invoice: drafted but not issued, or issued but not filed); data.errors says which',
                'content' => ['application/json' => ['schema' => $success]],
            ];
        }

        // A credit or debit note the organization holds for approval:
        // accepted, not issued, with the request it waits on - the same one
        // on a retry (NoteIssuing). Generating or sending a stored note says
        // so itself; the pipeline in its result.
        $holds = str_contains($body, 'holdIntegration(');

        if ($holds || ($data?->schema === 'PipelineResult' && str_contains($body, '->outcome('))) {
            $responses['202'] = [
                'description' => 'Accepted: a credit or debit note waits for approval in the organization; data.approval is the request, the same on a retry. It is issued once approved',
                'content' => ['application/json' => ['schema' => $holds ? $this->envelope('Success', new ResponseData([
                    'type' => 'object',
                    'required' => ['invoice_id', 'approval'],
                    'properties' => [
                        'invoice_id' => ['type' => 'string', 'format' => 'uuid'],
                        'approval' => ['$ref' => '#/components/schemas/Approval'],
                    ],
                ])) : $success]],
            ];
        }

        // The pipeline answers a retry with the status the invoice's state
        // earns now, and says it is one (PipelineController::replayed()).
        if (str_contains($body, '$this->replayed(')) {
            foreach (array_intersect(['201', '202', '207'], array_keys($responses)) as $replayed) {
                $responses[$replayed]['headers'][self::REPLAYED_HEADER] = self::replayedHeader();
            }
        }

        // A state the request cannot act on: a draft asked for its PDF, a
        // schedule paused twice. Read from the status the method answers.
        if (preg_match('/,\s*409\)/', $body) === 1) {
            $responses['409'] ??= $this->errorResponse('Conflict: the record\'s state does not allow it (CONFLICT); error.message says why');
        }

        if ($this->reader->security($route) !== []) {
            $responses['401'] = $this->errorResponse('Missing or invalid credentials');
            $responses['403'] = $this->errorResponse('Refused: the credential\'s status, scope, licence feature, environment or role');
        }

        if ($route->parameterNames() !== []) {
            $responses['404'] = $this->errorResponse('Not found, or not the caller\'s organization\'s');
        }

        if ($this->reader->rulesFor($route) !== [] || $this->reader->takesKey($route)) {
            $responses['422'] = $this->errorResponse('Validation failed (VALIDATION_ERROR), or the Idempotency-Key was used with another body (IDEMPOTENCY_KEY_REUSED)');
        }

        if ($this->reader->throttled($route)) {
            $responses['429'] = [
                ...$this->errorResponse('Too many requests, or the licence\'s allowance is used up'),
                'headers' => ['Retry-After' => [
                    'description' => 'Seconds to wait, where the limit says',
                    'schema' => ['type' => 'integer'],
                ]],
            ];
        }

        // Any write can meet another changing the same records - and one
        // that issues, a device busy issuing - and is answered 503 SYS_008,
        // to be sent again after Retry-After (bootstrap/app.php,
        // IssuingLifecycle).
        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
            $responses['503'] = [
                ...$this->errorResponse('Busy (SYS_008): the request met another changing the same records, or its device was issuing another invoice; send the same request again after Retry-After'),
                'headers' => ['Retry-After' => [
                    'description' => 'Seconds to wait before sending it again',
                    'schema' => ['type' => 'integer'],
                ]],
            ];
        }

        // The named refusals the method declares (#[AnswersError]), at their
        // status beside what is there already.
        foreach ($this->reader->declaredErrors($route) as $code) {
            $status = (string) $code->getHttpStatus();
            $described = $code->value.': '.$code->getMessage();

            $responses[$status] = isset($responses[$status])
                ? [...$responses[$status], 'description' => $responses[$status]['description'].'; or '.$described]
                : $this->errorResponse($described);
        }

        ksort($responses);

        return $responses;
    }

    /**
     * An envelope, by reference when nothing is said of its data, or written
     * out with the data the method declares.
     *
     * @return array<string, mixed>
     */
    private function envelope(string $name, ?ResponseData $data): array
    {
        if ($data === null) {
            return ['$ref' => '#/components/schemas/'.$name];
        }

        $item = is_string($data->schema) ? ['$ref' => '#/components/schemas/'.$data->schema] : $data->schema;

        if ($data->under !== null) {
            $item = ['type' => 'object', 'required' => [$data->under], 'properties' => [$data->under => $item]];
        }

        $schema = $this->envelopes()[$name];
        $schema['properties']['data'] = $data->list ? ['type' => 'array', 'items' => $item] : $item;
        $schema['required'] = array_values(array_unique([...$schema['required'], 'data']));

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function replayedHeader(): array
    {
        return [
            'description' => '"true" when the answer is a replay of an earlier request under the same Idempotency-Key, or of a step already done',
            'schema' => ['type' => 'string', 'enum' => ['true']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Error'],
                ],
            ],
        ];
    }
}
