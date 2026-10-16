<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Http\Controllers;

use App\Domains\Compliance\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Peppol\Services\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The service provider telling us what became of a document we sent:
 * accepted for transport, delivered or rejected by the buyer's provider,
 * confirmed by the authority.
 *
 * Public, because the provider holds no credential of ours; the signature is
 * the credential. Unsigned or stale callbacks are refused before the body is
 * read.
 */
class StatusWebhookController extends Controller
{
    public function __construct(
        private readonly AspGateway $gateway,
        private readonly DocumentStatus $status,
    ) {}

    /**
     * POST /api/peppol/webhooks/status
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->gateway->verifyWebhook($request)) {
            return ApiResponse::unauthorized('Callback signature refused');
        }

        // Unknown: not ours, or the provider called back before its own
        // answer to the submission reached us. Either way nothing is written,
        // and the provider's retry finds the message id in place.
        if (! $this->status->apply($this->gateway->parseStatusWebhook($request))) {
            return ApiResponse::notFound('Message not found');
        }

        return ApiResponse::success(null, 'Status recorded');
    }
}
