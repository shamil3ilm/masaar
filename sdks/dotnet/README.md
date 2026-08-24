# Masaar .NET SDK

ZATCA-compliant e-invoicing API client for .NET 8+ and C# 12.

## Installation

### NuGet Package Manager

```bash
Install-Package Masaar
```

### .NET CLI

```bash
dotnet add package Masaar
```

## Quick Start

```csharp
using Masaar;

// Initialize client
var client = new MasaarClient(new MasaarOptions
{
    BaseUrl = "http://localhost:8000",  // Your server URL
    ApiKey = "your_api_key",
    ApiSecret = "your_api_secret"
});

// Create an invoice
var invoice = await client.Invoices.CreateAsync(new CreateInvoiceRequest
{
    InvoiceNumber = "INV-2026-001",
    BuyerName = "Acme Corporation",
    BuyerVatNumber = "300000000000003",
    Lines =
    [
        new InvoiceLine
        {
            Description = "Consulting Services",
            Quantity = 10,
            UnitPrice = 100.00m,
            TaxRate = 15.0m
        }
    ]
});

Console.WriteLine($"Invoice created: {invoice.Id}");

// Submit to ZATCA
var result = await client.Compliance.SubmitAsync(invoice.Id);

if (result.Cleared)
{
    Console.WriteLine("Invoice cleared by ZATCA!");
    Console.WriteLine($"QR Code: {result.QrCode}");
}
```

## Features

### Invoice Management

```csharp
// List invoices
var invoices = await client.Invoices.ListAsync(new ListOptions
{
    Page = 1,
    Limit = 20,
    Status = "cleared"
});

// Get single invoice
var invoice = await client.Invoices.GetAsync("invoice-uuid");

// Create standard invoice (B2B)
var b2bInvoice = await client.Invoices.CreateAsync(new CreateInvoiceRequest
{
    InvoiceNumber = "INV-001",
    Type = InvoiceType.Standard,
    BuyerName = "Business Customer",
    BuyerVatNumber = "300000000000003",
    Lines = [new InvoiceLine { Description = "Service", Quantity = 1, UnitPrice = 1000.00m }]
});

// Create simplified invoice (B2C)
var b2cInvoice = await client.Invoices.CreateAsync(new CreateInvoiceRequest
{
    InvoiceNumber = "SINV-001",
    Type = InvoiceType.Simplified,
    BuyerName = "Walk-in Customer",
    Lines = [new InvoiceLine { Description = "Product", Quantity = 2, UnitPrice = 50.00m }]
});

// Create credit note
var creditNote = await client.Invoices.CreateCreditNoteAsync(new CreateCreditNoteRequest
{
    CreditNoteNumber = "CN-001",
    OriginalInvoiceId = "original-invoice-uuid",
    Lines = [new InvoiceLine { Description = "Returned Item", Quantity = 1, UnitPrice = 100.00m }]
});
```

### ZATCA Compliance

```csharp
// Generate compliance data
var generated = await client.Compliance.GenerateAsync(invoiceId);
Console.WriteLine($"Hash: {generated.Hash}");
Console.WriteLine($"QR Code: {generated.QrCode}");

// Validate before submission
var validation = await client.Compliance.ValidateAsync(invoiceId);
foreach (var warning in validation.Warnings)
{
    Console.WriteLine($"Warning: {warning}");
}

// Submit to ZATCA
var result = await client.Compliance.SubmitAsync(invoiceId);

// Check status
var status = await client.Compliance.StatusAsync(invoiceId);
```

### Webhooks

```csharp
// Subscribe to events
var webhook = await client.Webhooks.CreateAsync(new CreateWebhookRequest
{
    Url = "https://your-app.com/webhooks/masaar",
    Events = ["invoice.cleared", "invoice.rejected"],
    Secret = "your-webhook-secret"
});

// In your ASP.NET Core controller
[ApiController]
[Route("webhooks")]
public class WebhooksController : ControllerBase
{
    private readonly string _webhookSecret;

    [HttpPost("masaar")]
    public async Task<IActionResult> HandleWebhook()
    {
        using var reader = new StreamReader(Request.Body);
        var payload = await reader.ReadToEndAsync();
        var signature = Request.Headers["X-Masaar-Signature"].FirstOrDefault();

        if (!MasaarWebhook.VerifySignature(payload, signature, _webhookSecret))
        {
            return Unauthorized();
        }

        var webhookEvent = JsonSerializer.Deserialize<WebhookEvent>(payload);

        switch (webhookEvent.Type)
        {
            case "invoice.cleared":
                await HandleInvoiceCleared(webhookEvent);
                break;
            case "invoice.rejected":
                await HandleInvoiceRejected(webhookEvent);
                break;
        }

        return Ok();
    }
}
```

## Error Handling

```csharp
try
{
    var invoice = await client.Invoices.CreateAsync(request);
}
catch (AuthenticationException ex)
{
    Console.WriteLine($"Auth failed: {ex.Message}");
}
catch (ValidationException ex)
{
    Console.WriteLine($"Validation failed: {ex.Message}");
    foreach (var error in ex.Errors)
    {
        Console.WriteLine($"  - {error}");
    }
}
catch (ZatcaException ex)
{
    Console.WriteLine($"ZATCA error: {ex.Message}");
}
catch (RateLimitException ex)
{
    Console.WriteLine($"Rate limited - retry after {ex.RetryAfter} seconds");
}
catch (MasaarException ex)
{
    Console.WriteLine($"API error: {ex.Message}");
}
```

## Dependency Injection

```csharp
// Program.cs
builder.Services.AddMasaar(options =>
{
    options.BaseUrl = builder.Configuration["Masaar:BaseUrl"];
    options.ApiKey = builder.Configuration["Masaar:ApiKey"];
    options.ApiSecret = builder.Configuration["Masaar:ApiSecret"];
});

// Usage in your service
public class InvoiceService
{
    private readonly IMasaarClient _masaar;

    public InvoiceService(IMasaarClient masaar)
    {
        _masaar = masaar;
    }

    public async Task<Invoice> CreateAndSubmitAsync(CreateInvoiceRequest request, CancellationToken ct)
    {
        var invoice = await _masaar.Invoices.CreateAsync(request, ct);
        await _masaar.Compliance.SubmitAsync(invoice.Id, ct);
        return await _masaar.Invoices.GetAsync(invoice.Id, ct);
    }
}
```

## Requirements

- .NET 8.0 or higher
- System.Text.Json (included)

## License

MIT License - see LICENSE file for details.
