<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Webhook\Services\WebhookService;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * The shapes the API's main answers put in `data`, as OpenAPI components
 * (#[ResponseData] names them).
 *
 * An invoice and its lines are read from their models - each column a
 * request may set and how it is cast - because the API answers the model as
 * it serialises. The pipeline's answers and a webhook delivery are written
 * out from the code that builds them (PipelineResult::build,
 * PipelineController::status, WebhookService::attempt). Either way the
 * contract tests send real requests and hold the answers to these, so a
 * shape and its description cannot part without a test failing.
 *
 * Open, not closed: a key added within the version is allowed (API.md:
 * ignore keys you do not know). A key removed or retyped is what fails.
 */
final class ResponseSchemas
{
    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        return [
            'Invoice' => self::invoice(),
            'InvoiceLine' => self::model(new InvoiceLine, ['id', 'invoice_id']),
            'PipelineResult' => self::pipelineResult(),
            'PipelineStatus' => self::pipelineStatus(),
            'WebhookDelivery' => self::webhookDelivery(),
        ];
    }

    /** @return array<string, mixed> */
    private static function invoice(): array
    {
        $schema = self::model(new Invoice, ['id', 'org_id', 'status']);
        $schema['properties']['lines'] = ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/InvoiceLine'], 'description' => 'Where the answer includes them'];

        return $schema;
    }

    /**
     * A model as it serialises: its id and timestamps, and each column a
     * request may set, typed by its cast. A column with no cast is named
     * and left untyped, as nothing declares its type.
     *
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function model(Model $model, array $required): array
    {
        $casts = $model->getCasts();
        $properties = ['id' => ['type' => 'string', 'format' => 'uuid']];

        foreach ($model->getFillable() as $column) {
            $properties[$column] = self::cast($casts[$column] ?? null);
        }

        foreach (['created_at', 'updated_at'] as $column) {
            $properties[$column] = ['type' => ['string', 'null'], 'format' => 'date-time'];
        }

        return ['type' => 'object', 'required' => $required, 'properties' => $properties];
    }

    /** @return array<string, mixed> */
    private static function cast(?string $cast): array
    {
        if ($cast !== null && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
            return ['type' => ['string', 'null'], 'enum' => [...array_map(fn (BackedEnum $case) => $case->value, $cast::cases()), null]];
        }

        return match (explode(':', (string) $cast)[0]) {
            'decimal' => ['type' => ['string', 'null'], 'description' => 'A decimal, as a string'],
            'integer', 'int' => ['type' => ['integer', 'null']],
            'boolean', 'bool' => ['type' => ['boolean', 'null']],
            'array', 'json' => ['type' => ['object', 'array', 'null']],
            'date' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'A date, at midnight UTC'],
            'datetime', 'immutable_datetime' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            '' => ['description' => 'As stored'],
            default => ['type' => ['string', 'null']],
        };
    }

    /** @return array<string, mixed> */
    private static function totals(): array
    {
        $amount = ['type' => ['string', 'null'], 'description' => 'A decimal, as a string'];

        return [
            'type' => 'object',
            'required' => ['subtotal', 'discount_amount', 'tax_amount', 'total'],
            'properties' => ['subtotal' => $amount, 'discount_amount' => $amount, 'tax_amount' => $amount, 'total' => $amount],
        ];
    }

    /** @return array<string, mixed> */
    private static function statuses(): array
    {
        return ['type' => 'string', 'enum' => array_map(fn (InvoiceStatus $status) => $status->value, InvoiceStatus::cases())];
    }

    /**
     * PipelineResult::build: what /pipeline/submit, a batch line and a
     * split's invoice answer.
     *
     * @return array<string, mixed>
     */
    private static function pipelineResult(): array
    {
        $text = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'required' => ['invoice_id', 'invoice_number', 'status', 'compliance_status', 'totals', 'errors', 'warnings'],
            'properties' => [
                'invoice_id' => ['type' => 'string', 'format' => 'uuid'],
                'uuid' => ['type' => 'string', 'format' => 'uuid'],
                'invoice_number' => $text,
                'erp_reference_id' => $text,
                'status' => self::statuses(),
                'compliance_status' => ['type' => 'string', 'enum' => ['pending', 'cleared', 'reported', 'rejected']],
                'hash' => $text,
                'qr_code' => $text,
                'signed_xml' => ['type' => ['string', 'null'], 'description' => 'The document of record: the authority\'s stamped copy once a standard invoice is cleared'],
                'cleared_xml' => $text,
                'zatca_response' => ['type' => ['object', 'null'], 'description' => 'The filing: submission_id, state, clearance_status, reporting_status; null when nothing was filed'],
                'totals' => self::totals(),
                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'rejections' => [
                    'type' => 'array',
                    'description' => 'Only when rejected: each rule broken',
                    'items' => [
                        'type' => 'object',
                        'required' => ['message'],
                        'properties' => ['code' => $text, 'category' => $text, 'message' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
    }

    /**
     * PipelineController::status: the invoice now.
     *
     * @return array<string, mixed>
     */
    private static function pipelineStatus(): array
    {
        $text = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'required' => ['invoice_id', 'status', 'type', 'totals'],
            'properties' => [
                'invoice_id' => ['type' => 'string', 'format' => 'uuid'],
                'uuid' => ['type' => 'string', 'format' => 'uuid'],
                'invoice_number' => $text,
                'status' => self::statuses(),
                'type' => ['type' => 'string', 'enum' => array_map(fn (InvoiceType $type) => $type->value, InvoiceType::cases())],
                'hash' => $text,
                'qr_code' => $text,
                'signed_xml' => $text,
                'cleared_xml' => $text,
                'zatca_response' => ['type' => ['object', 'null']],
                'totals' => self::totals(),
                'issue_date' => $text,
                'created_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'updated_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ],
        ];
    }

    /**
     * WebhookService::attempt: the body POSTed to a webhook, signed in
     * X-Webhook-Signature. data is the event's own (WEBHOOKS.md).
     *
     * @return array<string, mixed>
     */
    private static function webhookDelivery(): array
    {
        return [
            'type' => 'object',
            'required' => ['id', 'event', 'timestamp', 'data'],
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The delivery, the same on every retry of it'],
                'event' => ['type' => 'string', 'enum' => [...WebhookService::EVENTS, 'webhook.test']],
                'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                'data' => ['type' => 'object', 'description' => 'The event\'s payload; an invoice event\'s carries invoice_id, invoice_number, status, type, total and currency'],
            ],
        ];
    }
}
