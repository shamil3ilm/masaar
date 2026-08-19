# Masaar Kotlin SDK

ZATCA-compliant e-invoicing API client for Kotlin 1.9+ and Android.

## Installation

### Gradle (Kotlin DSL)

```kotlin
dependencies {
    implementation("com.masaar:masaar-sdk:1.0.0")
}
```

### Gradle (Groovy)

```groovy
implementation 'com.masaar:masaar-sdk:1.0.0'
```

### Maven

```xml
<dependency>
    <groupId>com.masaar</groupId>
    <artifactId>masaar-sdk</artifactId>
    <version>1.0.0</version>
</dependency>
```

## Quick Start

```kotlin
import com.masaar.MasaarClient
import com.masaar.models.*

// Initialize client
val client = MasaarClient(
    baseUrl = "http://localhost:8000",  // Your server URL
    apiKey = "your_api_key",
    apiSecret = "your_api_secret"
)

// Create an invoice
val invoice = client.invoices.create(
    CreateInvoiceRequest(
        invoiceNumber = "INV-2026-001",
        buyerName = "Acme Corporation",
        buyerVatNumber = "300000000000003",
        lines = listOf(
            InvoiceLine(
                description = "Consulting Services",
                quantity = 10,
                unitPrice = 100.00,
                taxRate = 15.0
            )
        )
    )
)

println("Invoice created: ${invoice.id}")

// Submit to ZATCA
val result = client.compliance.submit(invoice.id)

if (result.cleared) {
    println("Invoice cleared by ZATCA!")
    println("QR Code: ${result.qrCode}")
}
```

## Features

### Invoice Management

```kotlin
// List invoices
val invoices = client.invoices.list(
    page = 1,
    limit = 20,
    status = "cleared"
)

// Get single invoice
val invoice = client.invoices.get("invoice-uuid")

// Create standard invoice (B2B)
val b2bInvoice = client.invoices.create(
    CreateInvoiceRequest(
        invoiceNumber = "INV-001",
        type = InvoiceType.STANDARD,
        buyerName = "Business Customer",
        buyerVatNumber = "300000000000003",
        lines = listOf(InvoiceLine("Service", 1, 1000.00))
    )
)

// Create simplified invoice (B2C)
val b2cInvoice = client.invoices.create(
    CreateInvoiceRequest(
        invoiceNumber = "SINV-001",
        type = InvoiceType.SIMPLIFIED,
        buyerName = "Walk-in Customer",
        lines = listOf(InvoiceLine("Product", 2, 50.00))
    )
)

// Create credit note
val creditNote = client.invoices.createCreditNote(
    CreateCreditNoteRequest(
        creditNoteNumber = "CN-001",
        originalInvoiceId = "original-invoice-uuid",
        lines = listOf(InvoiceLine("Returned Item", 1, 100.00))
    )
)
```

### ZATCA Compliance

```kotlin
// Generate compliance data
val generated = client.compliance.generate(invoiceId)
println("Hash: ${generated.hash}")
println("QR Code: ${generated.qrCode}")

// Validate before submission
val validation = client.compliance.validate(invoiceId)
validation.warnings.forEach { println("Warning: $it") }

// Submit to ZATCA
val result = client.compliance.submit(invoiceId)

// Check status
val status = client.compliance.status(invoiceId)
```

### Webhooks

```kotlin
// Subscribe to events
val webhook = client.webhooks.create(
    CreateWebhookRequest(
        url = "https://your-app.com/webhooks/masaar",
        events = listOf("invoice.cleared", "invoice.rejected"),
        secret = "your-webhook-secret"
    )
)

// Verify webhook signature (Spring Boot example)
@RestController
class WebhookController {

    @PostMapping("/webhooks/masaar")
    fun handleWebhook(
        @RequestBody payload: String,
        @RequestHeader("X-Masaar-Signature") signature: String
    ): ResponseEntity<Unit> {
        if (!MasaarWebhook.verifySignature(payload, signature, webhookSecret)) {
            return ResponseEntity.status(HttpStatus.UNAUTHORIZED).build()
        }

        val event = Json.decodeFromString<WebhookEvent>(payload)

        when (event.type) {
            "invoice.cleared" -> handleInvoiceCleared(event)
            "invoice.rejected" -> handleInvoiceRejected(event)
        }

        return ResponseEntity.ok().build()
    }
}
```

## Error Handling

```kotlin
try {
    val invoice = client.invoices.create(request)
} catch (e: AuthenticationException) {
    println("Auth failed: ${e.message}")
} catch (e: ValidationException) {
    println("Validation failed: ${e.message}")
    e.errors.forEach { println("  - $it") }
} catch (e: ZatcaException) {
    println("ZATCA error: ${e.message}")
} catch (e: RateLimitException) {
    println("Rate limited - retry after ${e.retryAfter} seconds")
} catch (e: MasaarException) {
    println("API error: ${e.message}")
}
```

## Android Integration

```kotlin
// In your Application class or DI module
val compliPayClient = MasaarClient(
    baseUrl = BuildConfig.MASAAR_BASE_URL,
    apiKey = BuildConfig.MASAAR_API_KEY,
    apiSecret = BuildConfig.MASAAR_API_SECRET
)

// In your ViewModel
class InvoiceViewModel(private val compliPay: MasaarClient) : ViewModel() {

    private val _invoiceState = MutableStateFlow<InvoiceState>(InvoiceState.Idle)
    val invoiceState: StateFlow<InvoiceState> = _invoiceState

    fun createInvoice(request: CreateInvoiceRequest) {
        viewModelScope.launch {
            _invoiceState.value = InvoiceState.Loading
            try {
                val invoice = compliPay.invoices.create(request)
                _invoiceState.value = InvoiceState.Success(invoice)
            } catch (e: MasaarException) {
                _invoiceState.value = InvoiceState.Error(e.message)
            }
        }
    }
}
```

## Requirements

- Kotlin 1.9 or higher
- kotlinx.serialization 1.6+
- OkHttp 4.12+ (Android) or Ktor (Multiplatform)

## License

MIT License - see LICENSE file for details.
