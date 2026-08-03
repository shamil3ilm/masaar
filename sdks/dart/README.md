# Masaar Dart SDK

ZATCA-compliant e-invoicing API client for Dart 3+ and Flutter.

## Installation

Add to your `pubspec.yaml`:

```yaml
dependencies:
  masaar: ^1.0.0
```

Then run:

```bash
dart pub get
# or for Flutter
flutter pub get
```

## Quick Start

```dart
import 'package:masaar/masaar.dart';

void main() async {
  // Initialize client
  final client = MasaarClient(
    baseUrl: 'http://localhost:8000',  // Your server URL
    apiKey: 'your_api_key',
    apiSecret: 'your_api_secret',
  );

  // Create an invoice
  final invoice = await client.invoices.create(
    CreateInvoiceRequest(
      invoiceNumber: 'INV-2026-001',
      buyerName: 'Acme Corporation',
      buyerVatNumber: '300000000000003',
      lines: [
        InvoiceLine(
          description: 'Consulting Services',
          quantity: 10,
          unitPrice: 100.00,
          taxRate: 15.0,
        ),
      ],
    ),
  );

  print('Invoice created: ${invoice.id}');

  // Submit to ZATCA
  final result = await client.compliance.submit(invoice.id);

  if (result.cleared) {
    print('Invoice cleared by ZATCA!');
    print('QR Code: ${result.qrCode}');
  }

  // Don't forget to close the client
  client.close();
}
```

## Features

### Invoice Management

```dart
// List invoices
final invoices = await client.invoices.list(
  page: 1,
  limit: 20,
  status: 'cleared',
);

// Get single invoice
final invoice = await client.invoices.get('invoice-uuid');

// Create standard invoice (B2B)
final b2bInvoice = await client.invoices.create(
  CreateInvoiceRequest(
    invoiceNumber: 'INV-001',
    type: InvoiceType.standard,
    buyerName: 'Business Customer',
    buyerVatNumber: '300000000000003',
    lines: [InvoiceLine(description: 'Service', quantity: 1, unitPrice: 1000.00)],
  ),
);

// Create simplified invoice (B2C)
final b2cInvoice = await client.invoices.create(
  CreateInvoiceRequest(
    invoiceNumber: 'SINV-001',
    type: InvoiceType.simplified,
    buyerName: 'Walk-in Customer',
    lines: [InvoiceLine(description: 'Product', quantity: 2, unitPrice: 50.00)],
  ),
);

// Create credit note
final creditNote = await client.invoices.createCreditNote(
  CreateCreditNoteRequest(
    creditNoteNumber: 'CN-001',
    originalInvoiceId: 'original-invoice-uuid',
    lines: [InvoiceLine(description: 'Returned Item', quantity: 1, unitPrice: 100.00)],
  ),
);
```

### ZATCA Compliance

```dart
// Generate compliance data
final generated = await client.compliance.generate(invoiceId);
print('Hash: ${generated.hash}');
print('QR Code: ${generated.qrCode}');

// Validate before submission
final validation = await client.compliance.validate(invoiceId);
for (final warning in validation.warnings) {
  print('Warning: $warning');
}

// Submit to ZATCA
final result = await client.compliance.submit(invoiceId);

// Check status
final status = await client.compliance.status(invoiceId);
```

### Webhooks

```dart
// Subscribe to events
final webhook = await client.webhooks.create(
  CreateWebhookRequest(
    url: 'https://your-app.com/webhooks/masaar',
    events: ['invoice.cleared', 'invoice.rejected'],
    secret: 'your-webhook-secret',
  ),
);

// Verify webhook signature
bool verifyWebhook(String payload, String signature, String secret) {
  return MasaarWebhook.verifySignature(payload, signature, secret);
}
```

## Error Handling

```dart
try {
  final invoice = await client.invoices.create(request);
} on AuthenticationException catch (e) {
  print('Auth failed: ${e.message}');
} on ValidationException catch (e) {
  print('Validation failed: ${e.message}');
  for (final error in e.errors) {
    print('  - $error');
  }
} on ZatcaException catch (e) {
  print('ZATCA error: ${e.message}');
} on RateLimitException catch (e) {
  print('Rate limited - retry after ${e.retryAfter} seconds');
} on MasaarException catch (e) {
  print('API error: ${e.message}');
}
```

## Flutter Integration

```dart
// Using Provider
class MasaarProvider extends ChangeNotifier {
  final MasaarClient _client;
  Invoice? _currentInvoice;
  bool _loading = false;
  String? _error;

  MasaarProvider(this._client);

  Invoice? get currentInvoice => _currentInvoice;
  bool get loading => _loading;
  String? get error => _error;

  Future<void> createInvoice(CreateInvoiceRequest request) async {
    _loading = true;
    _error = null;
    notifyListeners();

    try {
      _currentInvoice = await _client.invoices.create(request);
    } on MasaarException catch (e) {
      _error = e.message;
    } finally {
      _loading = false;
      notifyListeners();
    }
  }
}

// In your widget
class InvoiceScreen extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Consumer<MasaarProvider>(
      builder: (context, provider, child) {
        if (provider.loading) {
          return CircularProgressIndicator();
        }
        if (provider.error != null) {
          return Text('Error: ${provider.error}');
        }
        if (provider.currentInvoice != null) {
          return Text('Invoice: ${provider.currentInvoice!.id}');
        }
        return ElevatedButton(
          onPressed: () => provider.createInvoice(request),
          child: Text('Create Invoice'),
        );
      },
    );
  }
}
```

## Requirements

- Dart 3.0 or higher
- http package
- crypto package (for HMAC verification)

## License

MIT License - see LICENSE file for details.
