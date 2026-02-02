using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Threading;
using System.Threading.Tasks;

namespace CompliPay;

/// <summary>
/// CompliPay .NET SDK for ZATCA-compliant e-invoicing.
/// </summary>
/// <example>
/// var client = new CompliPayClient("https://api.complipay.com", "api_key", "api_secret");
///
/// var invoice = await client.Invoices.CreateAsync(new CreateInvoiceRequest
/// {
///     InvoiceNumber = "INV-001",
///     BuyerName = "Acme Corp",
///     Lines = new[] { new InvoiceLine { Description = "Service", Quantity = 1, UnitPrice = 100 } }
/// });
///
/// var result = await client.Compliance.SubmitAsync(invoice.Data.Id);
/// </example>
public class CompliPayClient : IDisposable
{
    private readonly HttpClient _httpClient;
    private readonly string _apiKey;
    private readonly string _apiSecret;
    private readonly JsonSerializerOptions _jsonOptions;

    public InvoicesResource Invoices { get; }
    public ComplianceResource Compliance { get; }
    public WebhooksResource Webhooks { get; }

    public CompliPayClient(string baseUrl, string apiKey, string apiSecret, HttpClient? httpClient = null)
    {
        _apiKey = apiKey ?? throw new ArgumentNullException(nameof(apiKey));
        _apiSecret = apiSecret ?? throw new ArgumentNullException(nameof(apiSecret));

        _httpClient = httpClient ?? new HttpClient();
        _httpClient.BaseAddress = new Uri(baseUrl.TrimEnd('/'));
        _httpClient.DefaultRequestHeaders.Add("X-Api-Key", _apiKey);
        _httpClient.DefaultRequestHeaders.Add("X-Api-Secret", _apiSecret);
        _httpClient.DefaultRequestHeaders.Add("Accept", "application/json");
        _httpClient.Timeout = TimeSpan.FromSeconds(30);

        _jsonOptions = new JsonSerializerOptions
        {
            PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
            DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull
        };

        Invoices = new InvoicesResource(this);
        Compliance = new ComplianceResource(this);
        Webhooks = new WebhooksResource(this);
    }

    internal async Task<ApiResponse<T>> GetAsync<T>(string endpoint, CancellationToken ct = default)
    {
        var response = await _httpClient.GetAsync(endpoint, ct);
        return await HandleResponseAsync<T>(response, ct);
    }

    internal async Task<ApiResponse<T>> PostAsync<T>(string endpoint, object? body = null, CancellationToken ct = default)
    {
        var content = body != null
            ? new StringContent(JsonSerializer.Serialize(body, _jsonOptions), Encoding.UTF8, "application/json")
            : null;
        var response = await _httpClient.PostAsync(endpoint, content, ct);
        return await HandleResponseAsync<T>(response, ct);
    }

    internal async Task<ApiResponse<T>> DeleteAsync<T>(string endpoint, CancellationToken ct = default)
    {
        var response = await _httpClient.DeleteAsync(endpoint, ct);
        return await HandleResponseAsync<T>(response, ct);
    }

    private async Task<ApiResponse<T>> HandleResponseAsync<T>(HttpResponseMessage response, CancellationToken ct)
    {
        var content = await response.Content.ReadAsStringAsync(ct);

        if (!response.IsSuccessStatusCode)
        {
            var error = JsonSerializer.Deserialize<ApiResponse<object>>(content, _jsonOptions);
            throw response.StatusCode switch
            {
                System.Net.HttpStatusCode.Unauthorized => new AuthenticationException(error?.Message ?? "Invalid credentials"),
                System.Net.HttpStatusCode.UnprocessableEntity => new ValidationException(error?.Message ?? "Validation failed", error?.Errors),
                System.Net.HttpStatusCode.TooManyRequests => new RateLimitException("Rate limit exceeded"),
                _ => new CompliPayException(error?.Message ?? $"Request failed: {response.StatusCode}", (int)response.StatusCode)
            };
        }

        return JsonSerializer.Deserialize<ApiResponse<T>>(content, _jsonOptions) ?? new ApiResponse<T>();
    }

    public void Dispose()
    {
        _httpClient?.Dispose();
    }
}

#region Models

public class ApiResponse<T>
{
    public bool Success { get; set; }
    public T? Data { get; set; }
    public string? Message { get; set; }
    public List<string>? Errors { get; set; }
}

public class Invoice
{
    public string Id { get; set; } = "";
    public string InvoiceNumber { get; set; } = "";
    public string Type { get; set; } = "";
    public string Status { get; set; } = "";
    public string BuyerName { get; set; } = "";
    public string? BuyerVatNumber { get; set; }
    public decimal Subtotal { get; set; }
    public decimal TaxAmount { get; set; }
    public decimal Total { get; set; }
    public string Currency { get; set; } = "SAR";
    public string? Hash { get; set; }
    public string? QrCode { get; set; }
    public string? ClearanceStatus { get; set; }
    public string? ReportingStatus { get; set; }
    public DateTime CreatedAt { get; set; }
}

public class InvoiceLine
{
    public string Description { get; set; } = "";
    public decimal Quantity { get; set; }
    public decimal UnitPrice { get; set; }
    public decimal TaxRate { get; set; } = 15;
    public string TaxCategory { get; set; } = "S";
    public string UnitCode { get; set; } = "PCE";
    public string? TaxExemptionCode { get; set; }
    public string? TaxExemptionReason { get; set; }
    public decimal Discount { get; set; }
}

public class CreateInvoiceRequest
{
    public string InvoiceNumber { get; set; } = "";
    public string Type { get; set; } = "standard";
    public string BuyerName { get; set; } = "";
    public string? BuyerVatNumber { get; set; }
    public Address? BuyerAddress { get; set; }
    public string? IssueDate { get; set; }
    public string Currency { get; set; } = "SAR";
    public string PaymentMeansCode { get; set; } = "10";
    public decimal DiscountAmount { get; set; }
    public string? Notes { get; set; }
    public string? BillingReferenceId { get; set; }
    public List<InvoiceLine> Lines { get; set; } = new();
}

public class Address
{
    public string Street { get; set; } = "";
    public string City { get; set; } = "";
    public string PostalCode { get; set; } = "";
    public string? District { get; set; }
    public string CountryCode { get; set; } = "SA";
}

public class ZatcaResult
{
    public string InvoiceId { get; set; } = "";
    public string Status { get; set; } = "";
    public string? Hash { get; set; }
    public string? QrCode { get; set; }
    public string? ClearanceStatus { get; set; }
    public string? ReportingStatus { get; set; }
    public string? ValidationStatus { get; set; }
    public List<string>? Warnings { get; set; }
    public List<string>? Errors { get; set; }

    public bool IsCleared => ClearanceStatus == "CLEARED";
    public bool IsReported => ReportingStatus == "REPORTED";
}

#endregion

#region Resources

public class InvoicesResource
{
    private readonly CompliPayClient _client;
    public InvoicesResource(CompliPayClient client) => _client = client;

    public Task<ApiResponse<List<Invoice>>> ListAsync(int page = 1, int perPage = 15, CancellationToken ct = default)
        => _client.GetAsync<List<Invoice>>($"/v1/invoices?page={page}&per_page={perPage}", ct);

    public Task<ApiResponse<Invoice>> GetAsync(string invoiceId, CancellationToken ct = default)
        => _client.GetAsync<Invoice>($"/v1/invoices/{invoiceId}", ct);

    public Task<ApiResponse<Invoice>> CreateAsync(CreateInvoiceRequest request, CancellationToken ct = default)
        => _client.PostAsync<Invoice>("/v1/invoices", request, ct);
}

public class ComplianceResource
{
    private readonly CompliPayClient _client;
    public ComplianceResource(CompliPayClient client) => _client = client;

    public Task<ApiResponse<ZatcaResult>> GenerateAsync(string invoiceId, CancellationToken ct = default)
        => _client.PostAsync<ZatcaResult>($"/api/compliance/zatca/generate/{invoiceId}", null, ct);

    public Task<ApiResponse<ZatcaResult>> ValidateAsync(string invoiceId, CancellationToken ct = default)
        => _client.PostAsync<ZatcaResult>($"/api/compliance/zatca/validate/{invoiceId}", null, ct);

    public Task<ApiResponse<ZatcaResult>> SubmitAsync(string invoiceId, CancellationToken ct = default)
        => _client.PostAsync<ZatcaResult>($"/api/compliance/zatca/submit/{invoiceId}", null, ct);

    public Task<ApiResponse<ZatcaResult>> StatusAsync(string invoiceId, CancellationToken ct = default)
        => _client.GetAsync<ZatcaResult>($"/api/compliance/zatca/status/{invoiceId}", ct);
}

public class WebhooksResource
{
    private readonly CompliPayClient _client;
    public WebhooksResource(CompliPayClient client) => _client = client;

    public static class Events
    {
        public const string InvoiceCreated = "invoice.created";
        public const string InvoiceSubmitted = "invoice.submitted";
        public const string InvoiceCleared = "invoice.cleared";
        public const string InvoiceReported = "invoice.reported";
        public const string InvoiceRejected = "invoice.rejected";
        public const string InvoiceWarning = "invoice.warning";
        public const string InvoiceFailed = "invoice.failed";
    }

    public static bool VerifySignature(byte[] payload, string signature, string secret)
    {
        using var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(secret));
        var hash = hmac.ComputeHash(payload);
        var expected = "sha256=" + BitConverter.ToString(hash).Replace("-", "").ToLowerInvariant();
        return CryptographicOperations.FixedTimeEquals(
            Encoding.UTF8.GetBytes(expected),
            Encoding.UTF8.GetBytes(signature));
    }
}

#endregion

#region Exceptions

public class CompliPayException : Exception
{
    public int StatusCode { get; }
    public List<string>? Errors { get; }

    public CompliPayException(string message, int statusCode = 0, List<string>? errors = null) : base(message)
    {
        StatusCode = statusCode;
        Errors = errors;
    }
}

public class AuthenticationException : CompliPayException
{
    public AuthenticationException(string message) : base(message, 401) { }
}

public class ValidationException : CompliPayException
{
    public ValidationException(string message, List<string>? errors = null) : base(message, 422, errors) { }
}

public class RateLimitException : CompliPayException
{
    public RateLimitException(string message) : base(message, 429) { }
}

public class ZatcaException : CompliPayException
{
    public ZatcaException(string message, List<string>? errors = null) : base(message, 0, errors) { }
}

#endregion
