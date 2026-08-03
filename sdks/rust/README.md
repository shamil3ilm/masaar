# Masaar Rust SDK

ZATCA-compliant e-invoicing API client for Rust 1.70+.

## Installation

Add to your `Cargo.toml`:

```toml
[dependencies]
masaar = "1.0"
```

With all features:

```toml
[dependencies]
masaar = { version = "1.0", features = ["full"] }
```

## Quick Start

```rust
use masaar::{MasaarClient, CreateInvoiceRequest, InvoiceLine};

#[tokio::main]
async fn main() -> Result<(), masaar::Error> {
    // Initialize client
    let client = MasaarClient::builder()
        .base_url("http://localhost:8000")  // Your server URL
        .api_key("your_api_key")
        .api_secret("your_api_secret")
        .build()?;

    // Create an invoice
    let invoice = client.invoices().create(
        CreateInvoiceRequest::builder()
            .invoice_number("INV-2026-001")
            .buyer_name("Acme Corporation")
            .buyer_vat_number("300000000000003")
            .line(InvoiceLine::builder()
                .description("Consulting Services")
                .quantity(10)
                .unit_price(100.00)
                .tax_rate(15.0)
                .build())
            .build()
    ).await?;

    println!("Invoice created: {}", invoice.id);

    // Submit to ZATCA
    let result = client.compliance().submit(&invoice.id).await?;

    if result.cleared {
        println!("Invoice cleared by ZATCA!");
        if let Some(qr) = &result.qr_code {
            println!("QR Code: {}", qr);
        }
    }

    Ok(())
}
```

## Features

### Invoice Management

```rust
// List invoices
let invoices = client.invoices()
    .list()
    .page(1)
    .limit(20)
    .status("cleared")
    .send()
    .await?;

// Get single invoice
let invoice = client.invoices().get("invoice-uuid").await?;

// Create standard invoice (B2B)
let b2b_invoice = client.invoices().create(
    CreateInvoiceRequest::builder()
        .invoice_number("INV-001")
        .invoice_type(InvoiceType::Standard)
        .buyer_name("Business Customer")
        .buyer_vat_number("300000000000003")
        .line(InvoiceLine::new("Service", 1, 1000.00))
        .build()
).await?;

// Create simplified invoice (B2C)
let b2c_invoice = client.invoices().create(
    CreateInvoiceRequest::builder()
        .invoice_number("SINV-001")
        .invoice_type(InvoiceType::Simplified)
        .buyer_name("Walk-in Customer")
        .line(InvoiceLine::new("Product", 2, 50.00))
        .build()
).await?;

// Create credit note
let credit_note = client.invoices().create_credit_note(
    CreateCreditNoteRequest::builder()
        .credit_note_number("CN-001")
        .original_invoice_id("original-invoice-uuid")
        .line(InvoiceLine::new("Returned Item", 1, 100.00))
        .build()
).await?;
```

### ZATCA Compliance

```rust
// Generate compliance data
let generated = client.compliance().generate(&invoice_id).await?;
println!("Hash: {}", generated.hash);
if let Some(qr) = &generated.qr_code {
    println!("QR Code: {}", qr);
}

// Validate before submission
let validation = client.compliance().validate(&invoice_id).await?;
for warning in &validation.warnings {
    println!("Warning: {}", warning);
}

// Submit to ZATCA
let result = client.compliance().submit(&invoice_id).await?;

// Check status
let status = client.compliance().status(&invoice_id).await?;
```

### Webhooks

```rust
use masaar::webhook;

// Subscribe to events
let webhook = client.webhooks().create(
    CreateWebhookRequest::builder()
        .url("https://your-app.com/webhooks/masaar")
        .events(vec!["invoice.cleared", "invoice.rejected"])
        .secret("your-webhook-secret")
        .build()
).await?;

// Verify webhook signature (Axum example)
async fn handle_webhook(
    headers: HeaderMap,
    body: String,
) -> impl IntoResponse {
    let signature = headers
        .get("X-Masaar-Signature")
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");

    if !webhook::verify_signature(&body, signature, WEBHOOK_SECRET) {
        return StatusCode::UNAUTHORIZED;
    }

    let event: WebhookEvent = serde_json::from_str(&body).unwrap();

    match event.event_type.as_str() {
        "invoice.cleared" => handle_invoice_cleared(&event),
        "invoice.rejected" => handle_invoice_rejected(&event),
        _ => {}
    }

    StatusCode::OK
}
```

## Error Handling

```rust
use masaar::Error;

match client.invoices().create(request).await {
    Ok(invoice) => println!("Created: {}", invoice.id),
    Err(Error::Authentication(msg)) => {
        eprintln!("Auth failed: {}", msg);
    }
    Err(Error::Validation { message, errors }) => {
        eprintln!("Validation failed: {}", message);
        for err in errors {
            eprintln!("  - {}", err);
        }
    }
    Err(Error::Zatca { message, errors }) => {
        eprintln!("ZATCA error: {}", message);
    }
    Err(Error::RateLimit { retry_after }) => {
        eprintln!("Rate limited - retry after {} seconds", retry_after);
    }
    Err(e) => eprintln!("Error: {}", e),
}
```

## Configuration Options

```rust
let client = MasaarClient::builder()
    .base_url("http://localhost:8000")
    .api_key("your_api_key")
    .api_secret("your_api_secret")
    .timeout(Duration::from_secs(30))
    .retries(3)
    .build()?;
```

## Cargo Features

| Feature | Description |
|---------|-------------|
| `default` | Basic functionality with reqwest |
| `rustls` | Use rustls instead of native-tls |
| `webhook` | Webhook signature verification |
| `full` | All features enabled |

## Requirements

- Rust 1.70 or higher
- tokio runtime
- reqwest, serde, thiserror (included as dependencies)

## License

MIT License - see LICENSE file for details.
