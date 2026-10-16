<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Http\Controllers;

use App\Domains\Compliance\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Peppol\Services\InboundDocuments;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The service provider delivering a document another supplier sent to one
 * of our organizations.
 *
 * Public and signed, like the status callback. A document for an endpoint
 * no organization here holds is answered 404 and not stored, so the
 * provider knows it was not taken.
 */
class InboundWebhookController extends Controller
{
    public function __construct(
        private readonly AspGateway $gateway,
        private readonly InboundDocuments $inbound,
    ) {}

    /**
     * POST /api/peppol/webhooks/inbound
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->gateway->verifyWebhook($request)) {
            return ApiResponse::unauthorized('Callback signature refused');
        }

        $document = $this->inbound->receive($this->gateway->parseInboundWebhook($request));

        if ($document === null) {
            return ApiResponse::notFound('Receiver not found');
        }

        return ApiResponse::success(['id' => $document->id], 'Document received');
    }
}
