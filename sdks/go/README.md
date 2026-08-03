# Masaar Go SDK

ZATCA-compliant e-invoicing API client for Go 1.18+.

## Installation

```bash
go get github.com/masaar/masaar-go
```

Or add to your `go.mod`:

```go
require github.com/masaar/masaar-go v1.0.0
```

## Quick Start

```go
package main

import (
    "context"
    "fmt"
    "log"

    "github.com/masaar/masaar-go"
)

func main() {
    // Initialize client
    client := masaar.NewClient(
        masaar.WithBaseURL("http://localhost:8000"), // Your server URL
        masaar.WithAPIKey("your_api_key"),
        masaar.WithAPISecret("your_api_secret"),
    )

    ctx := context.Background()

    // Create an invoice
    invoice, err := client.Invoices.Create(ctx, &masaar.CreateInvoiceRequest{
        InvoiceNumber:  "INV-2026-001",
        BuyerName:      "Acme Corporation",
        BuyerVATNumber: "300000000000003",
        Lines: []masaar.InvoiceLine{
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
invoices, err := client.Invoices.List(ctx, &masaar.ListOptions{
    Page:   1,
    Limit:  20,
    Status: "cleared",
})

// Get single invoice
invoice, err := client.Invoices.Get(ctx, "invoice-uuid")

// Create credit note
creditNote, err := client.Invoices.CreateCreditNote(ctx, &masaar.CreateCreditNoteRequest{
    CreditNoteNumber:  "CN-001",
    OriginalInvoiceID: "original-invoice-uuid",
    Lines: []masaar.InvoiceLine{
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
webhook, err := client.Webhooks.Create(ctx, &masaar.CreateWebhookRequest{
    URL:    "https://your-app.com/webhooks/masaar",
    Events: []string{"invoice.cleared", "invoice.rejected"},
    Secret: "your-webhook-secret",
})

// Verify webhook signature
func handleWebhook(w http.ResponseWriter, r *http.Request) {
    payload, _ := io.ReadAll(r.Body)
    signature := r.Header.Get("X-Masaar-Signature")

    if !masaar.VerifyWebhookSignature(payload, signature, webhookSecret) {
        http.Error(w, "Invalid signature", http.StatusUnauthorized)
        return
    }

    var event masaar.WebhookEvent
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
    var apiErr *masaar.APIError
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
client := masaar.NewClient(
    masaar.WithBaseURL("http://localhost:8000"),
    masaar.WithAPIKey("your_api_key"),
    masaar.WithAPISecret("your_api_secret"),
    masaar.WithTimeout(30 * time.Second),
    masaar.WithRetries(3),
    masaar.WithHTTPClient(customHTTPClient),
)
```

## Requirements

- Go 1.18 or higher (uses generics)

## License

MIT License - see LICENSE file for details.
