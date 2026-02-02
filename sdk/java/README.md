# CompliPay Java SDK

ZATCA-compliant e-invoicing API client for Java 11+.

Works with Spring Boot, Jakarta EE, Micronaut, Quarkus, or any Java application.

## Installation

### Maven

```xml
<dependency>
    <groupId>com.complipay</groupId>
    <artifactId>complipay-sdk</artifactId>
    <version>1.0.0</version>
</dependency>
```

### Gradle

```groovy
implementation 'com.complipay:complipay-sdk:1.0.0'
```

### Local Installation

```bash
cd sdk/java
mvn clean install
```

## Quick Start

```java
import com.complipay.CompliPayClient;
import com.complipay.models.*;
import com.complipay.exceptions.*;

// Initialize client
CompliPayClient client = new CompliPayClient.Builder()
    .baseUrl("https://api.your-domain.com")
    .apiKey("cp_live_your_api_key")
    .apiSecret("your_api_secret")
    .build();

// Create an invoice
CreateInvoiceRequest request = CreateInvoiceRequest.builder()
    .invoiceNumber("INV-2026-001")
    .buyerName("Acme Corporation")
    .buyerVatNumber("300000000000003")
    .buyerAddress(CreateInvoiceRequest.BuyerAddress.builder()
        .street("123 Business Street")
        .city("Riyadh")
        .district("Al Olaya")
        .postalCode("12345")
        .build())
    .addLine(InvoiceLine.builder()
        .description("Consulting Services")
        .quantity(10)
        .unitPrice(100.00)
        .taxRate(15.0)
        .build())
    .addLine(InvoiceLine.builder()
        .description("Software License")
        .quantity(1)
        .unitPrice(500.00)
        .taxRate(15.0)
        .build())
    .build();

ApiResponse<Invoice> invoiceResponse = client.invoices().create(request);
Invoice invoice = invoiceResponse.getData();

System.out.println("Invoice created: " + invoice.getId());

// Submit to ZATCA
ApiResponse<ZatcaResult> result = client.compliance().submit(invoice.getId());

if (result.getData().isCleared()) {
    System.out.println("Invoice cleared by ZATCA!");
    System.out.println("QR Code: " + result.getData().getQrCode());
}
```

## Features

### Invoice Management

```java
// List invoices
ApiResponse<List<Invoice>> invoices = client.invoices().list(1, 20, "cleared");

// Get single invoice
ApiResponse<Invoice> invoice = client.invoices().get("invoice-uuid");

// Create standard invoice (B2B)
ApiResponse<Invoice> b2bInvoice = client.invoices().create(
    CreateInvoiceRequest.builder()
        .invoiceNumber("INV-001")
        .buyerName("Business Customer")
        .buyerVatNumber("300000000000003")
        .standard()
        .addLine(InvoiceLine.builder()
            .description("Service")
            .quantity(1)
            .unitPrice(1000.00)
            .build())
        .build()
);

// Create simplified invoice (B2C)
ApiResponse<Invoice> b2cInvoice = client.invoices().create(
    CreateInvoiceRequest.builder()
        .invoiceNumber("SINV-001")
        .buyerName("Walk-in Customer")
        .simplified()
        .addLine(InvoiceLine.builder()
            .description("Retail Product")
            .quantity(2)
            .unitPrice(50.00)
            .build())
        .build()
);

// Create credit note
ApiResponse<Invoice> creditNote = client.invoices().createCreditNote(
    "CN-001",
    "Acme Corp",
    "original-invoice-uuid",
    List.of(InvoiceLine.builder()
        .description("Returned Item")
        .quantity(1)
        .unitPrice(100.00)
        .build())
);
```

### ZATCA Compliance

```java
// Generate compliance data without submitting
ApiResponse<ZatcaResult> generated = client.compliance().generate(invoiceId);
System.out.println("Hash: " + generated.getData().getHash());
System.out.println("QR Code: " + generated.getData().getQrCode());

// Validate before submission
ApiResponse<ZatcaResult> validation = client.compliance().validate(invoiceId);
if (validation.getData().hasWarnings()) {
    validation.getData().getWarnings().forEach(System.out::println);
}

// Submit to ZATCA
try {
    ApiResponse<ZatcaResult> result = client.compliance().submit(invoiceId);

    if (result.getData().isCleared()) {
        // B2B invoice - cleared in real-time
        System.out.println("Invoice cleared!");
    } else if (result.getData().isReported()) {
        // B2C invoice - reported for batch processing
        System.out.println("Invoice reported!");
    }
} catch (ZatcaException e) {
    // ZATCA rejected the invoice
    System.err.println("Submission failed: " + e.getMessage());
    e.getErrors().forEach(System.err::println);
}

// Check status
ApiResponse<ZatcaResult> status = client.compliance().status(invoiceId);
```

### Webhooks

```java
import com.complipay.resources.WebhooksResource;

// Subscribe to events
ApiResponse<?> webhook = client.webhooks().create(
    "https://your-app.com/webhooks/complipay",
    List.of(
        WebhooksResource.Events.INVOICE_CLEARED,
        WebhooksResource.Events.INVOICE_REJECTED,
        WebhooksResource.Events.INVOICE_WARNING
    ),
    "your-webhook-secret"
);

// In your webhook handler (Spring Boot example)
@PostMapping("/webhooks/complipay")
public ResponseEntity<Void> handleWebhook(
    @RequestBody String payload,
    @RequestHeader("X-CompliPay-Signature") String signature
) {
    // Verify signature
    if (!WebhooksResource.verifySignature(payload, signature, webhookSecret)) {
        return ResponseEntity.status(401).build();
    }

    // Process the event
    WebhookEvent event = gson.fromJson(payload, WebhookEvent.class);

    switch (event.getType()) {
        case "invoice.cleared":
            handleInvoiceCleared(event);
            break;
        case "invoice.rejected":
            handleInvoiceRejected(event);
            break;
    }

    return ResponseEntity.ok().build();
}
```

## Tax Categories

| Code | Description | VAT Rate |
|------|-------------|----------|
| S | Standard Rate | 15% |
| Z | Zero Rated | 0% |
| E | Exempt | 0% |
| O | Out of Scope | 0% |

```java
// Zero-rated export
InvoiceLine exportLine = InvoiceLine.builder()
    .description("Export Goods")
    .quantity(100)
    .unitPrice(10.00)
    .taxRate(0)
    .taxCategory("Z")
    .taxExemptionCode("VATEX-SA-32")
    .taxExemptionReason("Export of goods")
    .build();

// Exempt supply
InvoiceLine exemptLine = InvoiceLine.builder()
    .description("Financial Service")
    .quantity(1)
    .unitPrice(1000.00)
    .taxRate(0)
    .taxCategory("E")
    .taxExemptionCode("VATEX-SA-29")
    .taxExemptionReason("Financial services")
    .build();
```

## Error Handling

```java
try {
    ApiResponse<Invoice> response = client.invoices().create(request);
} catch (AuthenticationException e) {
    // Invalid API key or secret
    System.err.println("Auth failed: " + e.getMessage());
} catch (ValidationException e) {
    // Request validation failed
    System.err.println("Validation failed: " + e.getMessage());
    e.getErrors().forEach(System.err::println);
} catch (RateLimitException e) {
    // Too many requests
    System.err.println("Rate limited - please retry later");
} catch (ZatcaException e) {
    // ZATCA rejected the invoice
    System.err.println("ZATCA error: " + e.getMessage());
    e.getErrors().forEach(System.err::println);
} catch (NetworkException e) {
    // Network/connectivity issue
    System.err.println("Network error: " + e.getMessage());
} catch (CompliPayException e) {
    // Other API error
    System.err.println("API error: " + e.getMessage());
}
```

## Spring Boot Integration

```java
@Configuration
public class CompliPayConfig {

    @Value("${complipay.base-url}")
    private String baseUrl;

    @Value("${complipay.api-key}")
    private String apiKey;

    @Value("${complipay.api-secret}")
    private String apiSecret;

    @Bean
    public CompliPayClient compliPayClient() {
        return new CompliPayClient.Builder()
            .baseUrl(baseUrl)
            .apiKey(apiKey)
            .apiSecret(apiSecret)
            .timeout(Duration.ofSeconds(30))
            .build();
    }
}

@Service
public class InvoiceService {

    private final CompliPayClient compliPay;

    public InvoiceService(CompliPayClient compliPay) {
        this.compliPay = compliPay;
    }

    public Invoice createAndSubmit(CreateInvoiceRequest request) throws CompliPayException {
        // Create invoice
        ApiResponse<Invoice> response = compliPay.invoices().create(request);
        Invoice invoice = response.getData();

        // Submit to ZATCA
        compliPay.compliance().submit(invoice.getId());

        // Return updated invoice
        return compliPay.invoices().get(invoice.getId()).getData();
    }
}
```

## Requirements

- Java 11 or higher
- Gson 2.10+ (included as dependency)

## Support

- Documentation: https://docs.complipay.com
- Issues: https://github.com/complipay/complipay-java/issues
- Email: support@complipay.com

## License

MIT License - see LICENSE file for details.
