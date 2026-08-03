<?php

/**
 * Masaar PHP SDK
 *
 * ZATCA-compliant e-invoicing API client for PHP 7.4+
 * Compatible with Laravel 8, 9, 10, 11, 12 and any PHP application.
 *
 * @package Masaar
 * @version 1.0.0
 */

namespace Masaar;

use Exception;
use InvalidArgumentException;

/**
 * Base exception for Masaar errors.
 */
class MasaarException extends Exception
{
    /** @var array */
    protected $errors;

    public function __construct(string $message, int $code = 0, array $errors = [])
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

/**
 * Authentication error.
 */
class AuthenticationException extends MasaarException
{
    public function __construct(string $message = 'Invalid API key or token')
    {
        parent::__construct($message, 401);
    }
}

/**
 * Validation error.
 */
class ValidationException extends MasaarException
{
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message, 422, $errors);
    }
}

/**
 * ZATCA submission error.
 */
class ZatcaException extends MasaarException
{
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message, 0, $errors);
    }
}

/**
 * Invoice line item.
 */
class InvoiceLine
{
    /** @var string */
    public $description;

    /** @var float */
    public $quantity;

    /** @var float */
    public $unitPrice;

    /** @var float */
    public $taxRate;

    /** @var string */
    public $taxCategory;

    /** @var string */
    public $unitCode;

    /** @var string|null */
    public $taxExemptionCode;

    /** @var string|null */
    public $taxExemptionReason;

    /** @var string|null */
    public $itemClassificationCode;

    /** @var float */
    public $discount;

    public function __construct(
        string $description,
        float $quantity,
        float $unitPrice,
        float $taxRate = 15.0,
        string $taxCategory = 'S',
        string $unitCode = 'PCE',
        ?string $taxExemptionCode = null,
        ?string $taxExemptionReason = null,
        ?string $itemClassificationCode = null,
        float $discount = 0.0
    ) {
        $this->description = $description;
        $this->quantity = $quantity;
        $this->unitPrice = $unitPrice;
        $this->taxRate = $taxRate;
        $this->taxCategory = $taxCategory;
        $this->unitCode = $unitCode;
        $this->taxExemptionCode = $taxExemptionCode;
        $this->taxExemptionReason = $taxExemptionReason;
        $this->itemClassificationCode = $itemClassificationCode;
        $this->discount = $discount;
    }

    public function toArray(): array
    {
        $data = [
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'tax_rate' => $this->taxRate,
            'tax_category' => $this->taxCategory,
            'unit_code' => $this->unitCode,
        ];

        if ($this->taxExemptionCode !== null) {
            $data['tax_exemption_code'] = $this->taxExemptionCode;
        }
        if ($this->taxExemptionReason !== null) {
            $data['tax_exemption_reason'] = $this->taxExemptionReason;
        }
        if ($this->itemClassificationCode !== null) {
            $data['item_classification_code'] = $this->itemClassificationCode;
        }
        if ($this->discount > 0) {
            $data['discount'] = $this->discount;
        }

        return $data;
    }
}

/**
 * HTTP client for API requests.
 */
class HttpClient
{
    /** @var string */
    private $baseUrl;

    /** @var string|null */
    private $apiKey;

    /** @var string|null */
    private $jwtToken;

    /** @var int */
    private $timeout;

    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        ?string $jwtToken = null,
        int $timeout = 30
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->jwtToken = $jwtToken;
        $this->timeout = $timeout;
    }

    private function getHeaders(): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($this->apiKey !== null) {
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        } elseif ($this->jwtToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->jwtToken;
        }

        return $headers;
    }

    /**
     * @throws MasaarException
     */
    private function handleResponse(array $response): array
    {
        $statusCode = $response['status'];
        $data = $response['data'];

        if ($statusCode === 401) {
            throw new AuthenticationException();
        }

        if ($statusCode === 422) {
            throw new ValidationException(
                $data['message'] ?? 'Validation failed',
                $data['errors'] ?? []
            );
        }

        if ($statusCode >= 400) {
            throw new MasaarException(
                $data['message'] ?? "Request failed with status {$statusCode}",
                $statusCode,
                $data['errors'] ?? []
            );
        }

        return $data;
    }

    private function request(string $method, string $endpoint, ?array $data = null): array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $this->getHeaders(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw new MasaarException("cURL error: {$error}");
        }

        $responseData = json_decode($response, true) ?? ['message' => $response];

        return $this->handleResponse([
            'status' => $statusCode,
            'data' => $responseData,
        ]);
    }

    public function get(string $endpoint, array $params = []): array
    {
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        return $this->request('GET', $endpoint);
    }

    public function post(string $endpoint, ?array $data = null): array
    {
        return $this->request('POST', $endpoint, $data);
    }

    public function put(string $endpoint, ?array $data = null): array
    {
        return $this->request('PUT', $endpoint, $data);
    }

    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }
}

/**
 * Invoices resource.
 */
class InvoicesResource
{
    /** @var HttpClient */
    private $client;

    public function __construct(HttpClient $client)
    {
        $this->client = $client;
    }

    /**
     * Validate resource ID format (UUID or alphanumeric).
     *
     * @throws InvalidArgumentException
     */
    private function validateId(string $id, string $name = 'ID'): void
    {
        if (empty($id)) {
            throw new InvalidArgumentException("{$name} cannot be empty");
        }

        // Allow UUIDs and alphanumeric IDs (no path traversal characters)
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $id)) {
            throw new InvalidArgumentException("Invalid {$name} format");
        }

        if (strlen($id) > 64) {
            throw new InvalidArgumentException("{$name} exceeds maximum length");
        }
    }

    public function list(int $page = 1, int $perPage = 15, ?string $status = null): array
    {
        $params = ['page' => $page, 'per_page' => min(max($perPage, 1), 100)];
        if ($status !== null) {
            $params['status'] = $status;
        }
        return $this->client->get('/v1/invoices', $params);
    }

    public function get(string $invoiceId): array
    {
        $this->validateId($invoiceId, 'Invoice ID');
        return $this->client->get("/v1/invoices/{$invoiceId}");
    }

    /**
     * @param InvoiceLine[]|array[] $lines
     */
    public function create(
        string $invoiceNumber,
        string $buyerName,
        array $lines,
        string $type = 'standard',
        ?string $buyerVatNumber = null,
        ?array $buyerAddress = null,
        ?string $issueDate = null,
        string $currency = 'SAR',
        string $paymentMeansCode = '10',
        float $discountAmount = 0.0,
        ?string $notes = null,
        ?string $billingReferenceId = null
    ): array {
        $data = [
            'invoice_number' => $invoiceNumber,
            'type' => $type,
            'buyer_name' => $buyerName,
            'currency' => $currency,
            'payment_means_code' => $paymentMeansCode,
            'lines' => array_map(function ($line) {
                return $line instanceof InvoiceLine ? $line->toArray() : $line;
            }, $lines),
        ];

        if ($buyerVatNumber !== null) {
            $data['buyer_vat_number'] = $buyerVatNumber;
        }
        if ($buyerAddress !== null) {
            $data['buyer_address'] = $buyerAddress;
        }
        if ($issueDate !== null) {
            $data['issue_date'] = $issueDate;
        }
        if ($discountAmount > 0) {
            $data['discount_amount'] = $discountAmount;
        }
        if ($notes !== null) {
            $data['notes'] = $notes;
        }
        if ($billingReferenceId !== null) {
            $data['billing_reference_id'] = $billingReferenceId;
        }

        return $this->client->post('/v1/invoices', $data);
    }

    /**
     * @param InvoiceLine[]|array[] $lines
     */
    public function createCreditNote(
        string $invoiceNumber,
        string $buyerName,
        array $lines,
        string $billingReferenceId,
        string $adjustmentReason,
        ?string $buyerVatNumber = null
    ): array {
        return $this->create(
            $invoiceNumber,
            $buyerName,
            $lines,
            'credit_note',
            $buyerVatNumber,
            null,
            null,
            'SAR',
            '10',
            0.0,
            $adjustmentReason,
            $billingReferenceId
        );
    }

    /**
     * @param InvoiceLine[]|array[] $lines
     */
    public function createDebitNote(
        string $invoiceNumber,
        string $buyerName,
        array $lines,
        string $billingReferenceId,
        string $adjustmentReason,
        ?string $buyerVatNumber = null
    ): array {
        return $this->create(
            $invoiceNumber,
            $buyerName,
            $lines,
            'debit_note',
            $buyerVatNumber,
            null,
            null,
            'SAR',
            '10',
            0.0,
            $adjustmentReason,
            $billingReferenceId
        );
    }
}

/**
 * Compliance resource.
 */
class ComplianceResource
{
    /** @var HttpClient */
    private $client;

    public function __construct(HttpClient $client)
    {
        $this->client = $client;
    }

    /**
     * Validate resource ID format.
     *
     * @throws InvalidArgumentException
     */
    private function validateId(string $id): void
    {
        if (empty($id) || !preg_match('/^[a-zA-Z0-9\-_]+$/', $id) || strlen($id) > 64) {
            throw new InvalidArgumentException('Invalid invoice ID format');
        }
    }

    public function generate(string $invoiceId): array
    {
        $this->validateId($invoiceId);
        return $this->client->post("/api/compliance/zatca/generate/{$invoiceId}");
    }

    public function validate(string $invoiceId): array
    {
        $this->validateId($invoiceId);
        return $this->client->post("/api/compliance/zatca/validate/{$invoiceId}");
    }

    /**
     * @throws ZatcaException
     */
    public function submit(string $invoiceId): array
    {
        $this->validateId($invoiceId);
        $result = $this->client->post("/api/compliance/zatca/submit/{$invoiceId}");

        if (!($result['success'] ?? true)) {
            throw new ZatcaException(
                'ZATCA submission failed',
                $result['errors'] ?? []
            );
        }

        return $result;
    }

    public function status(string $invoiceId): array
    {
        $this->validateId($invoiceId);
        return $this->client->get("/api/compliance/zatca/status/{$invoiceId}");
    }
}

/**
 * Webhooks resource.
 */
class WebhooksResource
{
    /** @var HttpClient */
    private $client;

    public function __construct(HttpClient $client)
    {
        $this->client = $client;
    }

    public function list(): array
    {
        return $this->client->get('/api/webhooks');
    }

    public function create(string $url, array $events, ?string $secret = null): array
    {
        $data = ['url' => $url, 'events' => $events];
        if ($secret !== null) {
            $data['secret'] = $secret;
        }
        return $this->client->post('/api/webhooks', $data);
    }

    public function delete(string $webhookId): array
    {
        return $this->client->delete("/api/webhooks/{$webhookId}");
    }

    /**
     * Verify webhook signature.
     */
    public static function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }
}

/**
 * Masaar API Client.
 *
 * ZATCA-compliant e-invoicing API client for PHP 7.4+
 *
 * @example
 * $client = new MasaarClient([
 *     'base_url' => 'https://api.masaar.com',
 *     'api_key' => 'your_api_key',
 * ]);
 *
 * $invoice = $client->invoices->create(
 *     'INV-001',
 *     'Acme Corp',
 *     [new InvoiceLine('Service', 1, 100.0)]
 * );
 *
 * $client->compliance->submit($invoice['data']['id']);
 */
class MasaarClient
{
    /** @var InvoicesResource */
    public $invoices;

    /** @var ComplianceResource */
    public $compliance;

    /** @var WebhooksResource */
    public $webhooks;

    /** @var HttpClient */
    private $http;

    /**
     * @param array $config [
     *     'base_url' => string (required),
     *     'api_key' => string (optional),
     *     'jwt_token' => string (optional),
     *     'timeout' => int (default: 30),
     * ]
     */
    public function __construct(array $config)
    {
        if (!isset($config['base_url'])) {
            throw new InvalidArgumentException('base_url is required');
        }

        if (!isset($config['api_key']) && !isset($config['jwt_token'])) {
            throw new InvalidArgumentException('Either api_key or jwt_token must be provided');
        }

        $this->http = new HttpClient(
            $config['base_url'],
            $config['api_key'] ?? null,
            $config['jwt_token'] ?? null,
            $config['timeout'] ?? 30
        );

        $this->invoices = new InvoicesResource($this->http);
        $this->compliance = new ComplianceResource($this->http);
        $this->webhooks = new WebhooksResource($this->http);
    }

    public function health(): array
    {
        return $this->http->get('/api/health');
    }
}
