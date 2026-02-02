# CompliPay Go SDK

ZATCA-compliant e-invoicing API client for Go 1.18+.

## Installation

```bash
go get github.com/complipay/complipay-go
```

Or add to your `go.mod`:

```go
require github.com/complipay/complipay-go v1.0.0
```

## Quick Start

```go
package main

import (
    "context"
    "fmt"
    "log"

    "github.com/complipay/complipay-go"
)

func main() {
    // Initialize client
    client := complipay.NewClient(
        complipay.WithBaseURL("http://localhost:8000"), // Your server URL
        complipay.WithAPIKey("your_api_key"),
        complipay.WithAPISecret("your_api_secret"),
    )

    ctx := context.Background()

    // Create an invoice
    invoice, err := client.Invoices.Create(ctx, &complipay.CreateInvoiceRequest{
        InvoiceNumber:  "INV-2026-001",
        BuyerName:      "Acme Corporation",
        BuyerVATNumber: "300000000000003",
        Lines: []complipay.InvoiceLine{
            {
                Description: "Consulting Services",
                Quantity:    10,
                UnitPrice:   100.00,
                TaxRate:     15.0,
            },
        },
    })
    if err != nil {
        log.Fatal(err)
    }

    fmt.Printf("Invoice created: %s\n", invoice.ID)

    // Submit to ZATCA
    result, err := client.Compliance.Submit(ctx, invoice.ID)
    if err != nil {
        log.Fatal(err)
    }

    if result.Cleared {
        fmt.Println("Invoice cleared by ZATCA!")
        fmt.Printf("QR Code: %s\n", result.QRCode)
    }
}
```

## Features

### Invoice Management

```go
// List invoices
invoices, err := client.Invoices.List(ctx, &complipay.ListOptions{
    Page:   1,
    Limit:  20,
    Status: "cleared",
})

// Get single invoice
invoice, err := client.Invoices.Get(ctx, "invoice-uuid")

// Create credit note
creditNote, err := client.Invoices.CreateCreditNote(ctx, &complipay.CreateCreditNoteRequest{
    CreditNoteNumber:  "CN-001",
    OriginalInvoiceID: "original-invoice-uuid",
    Lines: []complipay.InvoiceLine{
        {Description: "Returned Item", Quantity: 1, UnitPrice: 100.00},
    },
})
```

### ZATCA Compliance

```go
// Generate compliance data
generated, err := client.Compliance.Generate(ctx, invoiceID)
fmt.Printf("Hash: %s\n", generated.Hash)
fmt.Printf("QR Code: %s\n", generated.QRCode)

// Validate before submission
validation, err := client.Compliance.Validate(ctx, invoiceID)
for _, warning := range validation.Warnings {
    fmt.Printf("Warning: %s\n", warning)
}

// Submit to ZATCA
result, err := client.Compliance.Submit(ctx, invoiceID)

// Check status
status, err := client.Compliance.Status(ctx, invoiceID)
```

### Webhooks

```go
// Subscribe to events
webhook, err := client.Webhooks.Create(ctx, &complipay.CreateWebhookRequest{
    URL:    "https://your-app.com/webhooks/complipay",
    Events: []string{"invoice.cleared", "invoice.rejected"},
    Secret: "your-webhook-secret",
})

// Verify webhook signature
func handleWebhook(w http.ResponseWriter, r *http.Request) {
    payload, _ := io.ReadAll(r.Body)
    signature := r.Header.Get("X-CompliPay-Signature")

    if !complipay.VerifyWebhookSignature(payload, signature, webhookSecret) {
        http.Error(w, "Invalid signature", http.StatusUnauthorized)
        return
    }

    var event complipay.WebhookEvent
    json.Unmarshal(payload, &event)

    switch event.Type {
    case "invoice.cleared":
        handleInvoiceCleared(event)
    case "invoice.rejected":
        handleInvoiceRejected(event)
    }

    w.WriteHeader(http.StatusOK)
}
```

## Error Handling

```go
result, err := client.Invoices.Create(ctx, request)
if err != nil {
    var apiErr *complipay.APIError
    if errors.As(err, &apiErr) {
        switch apiErr.Code {
        case "AUTH_INVALID_KEY":
            log.Println("Invalid API key")
        case "VAL_INVALID_FORMAT":
            log.Println("Validation failed:", apiErr.Message)
        case "ZATCA_REJECTED":
            log.Println("ZATCA rejected:", apiErr.Errors)
        }
    }
    return
}
```

## Configuration Options

```go
client := complipay.NewClient(
    complipay.WithBaseURL("http://localhost:8000"),
    complipay.WithAPIKey("your_api_key"),
    complipay.WithAPISecret("your_api_secret"),
    complipay.WithTimeout(30 * time.Second),
    complipay.WithRetries(3),
    complipay.WithHTTPClient(customHTTPClient),
)
```

## Requirements

- Go 1.18 or higher (uses generics)

## License

MIT License - see LICENSE file for details.
