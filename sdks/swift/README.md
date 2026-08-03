# Masaar Swift SDK

ZATCA-compliant e-invoicing API client for Swift 5.9+ (iOS, macOS, watchOS, tvOS).

## Installation

### Swift Package Manager

Add to your `Package.swift`:

```swift
dependencies: [
    .package(url: "https://github.com/masaar/masaar-swift.git", from: "1.0.0")
]
```

Or in Xcode: File → Add Packages → Enter the repository URL.

### CocoaPods

```ruby
pod 'Masaar', '~> 1.0'
```

## Quick Start

```swift
import Masaar

// Initialize client
let client = MasaarClient(
    baseURL: URL(string: "http://localhost:8000")!,  // Your server URL
    apiKey: "your_api_key",
    apiSecret: "your_api_secret"
)

// Create an invoice
Task {
    do {
        let invoice = try await client.invoices.create(
            CreateInvoiceRequest(
                invoiceNumber: "INV-2026-001",
                buyerName: "Acme Corporation",
                buyerVatNumber: "300000000000003",
                lines: [
                    InvoiceLine(
                        description: "Consulting Services",
                        quantity: 10,
                        unitPrice: 100.00,
                        taxRate: 15.0
                    )
                ]
            )
        )

        print("Invoice created: \(invoice.id)")

        // Submit to ZATCA
        let result = try await client.compliance.submit(invoiceId: invoice.id)

        if result.cleared {
            print("Invoice cleared by ZATCA!")
            print("QR Code: \(result.qrCode ?? "")")
        }
    } catch {
        print("Error: \(error)")
    }
}
```

## Features

### Invoice Management

```swift
// List invoices
let invoices = try await client.invoices.list(page: 1, limit: 20, status: .cleared)

// Get single invoice
let invoice = try await client.invoices.get(id: "invoice-uuid")

// Create standard invoice (B2B)
let b2bInvoice = try await client.invoices.create(
    CreateInvoiceRequest(
        invoiceNumber: "INV-001",
        type: .standard,
        buyerName: "Business Customer",
        buyerVatNumber: "300000000000003",
        lines: [InvoiceLine(description: "Service", quantity: 1, unitPrice: 1000.00)]
    )
)

// Create simplified invoice (B2C)
let b2cInvoice = try await client.invoices.create(
    CreateInvoiceRequest(
        invoiceNumber: "SINV-001",
        type: .simplified,
        buyerName: "Walk-in Customer",
        lines: [InvoiceLine(description: "Product", quantity: 2, unitPrice: 50.00)]
    )
)

// Create credit note
let creditNote = try await client.invoices.createCreditNote(
    CreateCreditNoteRequest(
        creditNoteNumber: "CN-001",
        originalInvoiceId: "original-invoice-uuid",
        lines: [InvoiceLine(description: "Returned Item", quantity: 1, unitPrice: 100.00)]
    )
)
```

### ZATCA Compliance

```swift
// Generate compliance data
let generated = try await client.compliance.generate(invoiceId: invoiceId)
print("Hash: \(generated.hash)")
print("QR Code: \(generated.qrCode ?? "")")

// Validate before submission
let validation = try await client.compliance.validate(invoiceId: invoiceId)
for warning in validation.warnings {
    print("Warning: \(warning)")
}

// Submit to ZATCA
let result = try await client.compliance.submit(invoiceId: invoiceId)

// Check status
let status = try await client.compliance.status(invoiceId: invoiceId)
```

### Webhooks

```swift
// Subscribe to events
let webhook = try await client.webhooks.create(
    CreateWebhookRequest(
        url: "https://your-app.com/webhooks/masaar",
        events: [.invoiceCleared, .invoiceRejected],
        secret: "your-webhook-secret"
    )
)

// Verify webhook signature
func handleWebhook(payload: Data, signature: String) -> Bool {
    return MasaarWebhook.verifySignature(
        payload: payload,
        signature: signature,
        secret: webhookSecret
    )
}
```

## Error Handling

```swift
do {
    let invoice = try await client.invoices.create(request)
} catch MasaarError.authentication(let message) {
    print("Auth failed: \(message)")
} catch MasaarError.validation(let message, let errors) {
    print("Validation failed: \(message)")
    errors.forEach { print("  - \($0)") }
} catch MasaarError.zatca(let message, let errors) {
    print("ZATCA error: \(message)")
} catch MasaarError.rateLimit(let retryAfter) {
    print("Rate limited - retry after \(retryAfter) seconds")
} catch {
    print("Error: \(error)")
}
```

## SwiftUI Integration

```swift
@MainActor
class InvoiceViewModel: ObservableObject {
    private let client: MasaarClient

    @Published var invoice: Invoice?
    @Published var isLoading = false
    @Published var error: String?

    init(client: MasaarClient) {
        self.client = client
    }

    func createInvoice(_ request: CreateInvoiceRequest) async {
        isLoading = true
        error = nil

        do {
            invoice = try await client.invoices.create(request)
        } catch {
            self.error = error.localizedDescription
        }

        isLoading = false
    }
}

struct InvoiceView: View {
    @StateObject private var viewModel: InvoiceViewModel

    var body: some View {
        VStack {
            if viewModel.isLoading {
                ProgressView()
            } else if let error = viewModel.error {
                Text("Error: \(error)")
                    .foregroundColor(.red)
            } else if let invoice = viewModel.invoice {
                Text("Invoice: \(invoice.id)")
            } else {
                Button("Create Invoice") {
                    Task {
                        await viewModel.createInvoice(request)
                    }
                }
            }
        }
    }
}
```

## Requirements

- Swift 5.9 or higher
- iOS 15.0+ / macOS 12.0+ / watchOS 8.0+ / tvOS 15.0+
- CryptoKit (included in Apple platforms)

## License

MIT License - see LICENSE file for details.
