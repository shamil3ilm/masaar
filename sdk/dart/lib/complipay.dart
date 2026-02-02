/// CompliPay Dart/Flutter SDK for ZATCA-compliant e-invoicing.
///
/// Usage:
/// ```dart
/// final client = CompliPayClient(
///   baseUrl: 'https://api.complipay.com',
///   apiKey: 'your_api_key',
///   apiSecret: 'your_api_secret',
/// );
///
/// final invoice = await client.invoices.create(CreateInvoiceRequest(
///   invoiceNumber: 'INV-001',
///   buyerName: 'Acme Corp',
///   lines: [InvoiceLine(description: 'Service', quantity: 1, unitPrice: 100)],
/// ));
///
/// final result = await client.compliance.submit(invoice.data!.id);
/// ```
library complipay;

import 'dart:convert';
import 'dart:typed_data';
import 'package:crypto/crypto.dart';
import 'package:http/http.dart' as http;

// Client
class CompliPayClient {
  final String baseUrl;
  final String apiKey;
  final String apiSecret;
  final Duration timeout;
  final http.Client _httpClient;

  late final InvoicesResource invoices;
  late final ComplianceResource compliance;
  late final WebhooksResource webhooks;

  CompliPayClient({
    required this.baseUrl,
    required this.apiKey,
    required this.apiSecret,
    this.timeout = const Duration(seconds: 30),
    http.Client? httpClient,
  }) : _httpClient = httpClient ?? http.Client() {
    invoices = InvoicesResource(this);
    compliance = ComplianceResource(this);
    webhooks = WebhooksResource(this);
  }

  Future<ApiResponse<T>> get<T>(String endpoint, T Function(Map<String, dynamic>) fromJson) async {
    return _request('GET', endpoint, null, fromJson);
  }

  Future<ApiResponse<T>> post<T>(String endpoint, Map<String, dynamic>? body, T Function(Map<String, dynamic>) fromJson) async {
    return _request('POST', endpoint, body, fromJson);
  }

  Future<ApiResponse<T>> delete<T>(String endpoint, T Function(Map<String, dynamic>) fromJson) async {
    return _request('DELETE', endpoint, null, fromJson);
  }

  Future<ApiResponse<T>> _request<T>(
    String method,
    String endpoint,
    Map<String, dynamic>? body,
    T Function(Map<String, dynamic>) fromJson,
  ) async {
    final uri = Uri.parse('${baseUrl.replaceAll(RegExp(r'/$'), '')}$endpoint');
    final headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-Api-Key': apiKey,
      'X-Api-Secret': apiSecret,
    };

    http.Response response;
    try {
      switch (method) {
        case 'GET':
          response = await _httpClient.get(uri, headers: headers).timeout(timeout);
          break;
        case 'POST':
          response = await _httpClient.post(uri, headers: headers, body: body != null ? jsonEncode(body) : null).timeout(timeout);
          break;
        case 'DELETE':
          response = await _httpClient.delete(uri, headers: headers).timeout(timeout);
          break;
        default:
          throw CompliPayException('Unsupported method: $method');
      }
    } catch (e) {
      throw NetworkException('Network error: $e');
    }

    return _handleResponse(response, fromJson);
  }

  ApiResponse<T> _handleResponse<T>(http.Response response, T Function(Map<String, dynamic>) fromJson) {
    final body = jsonDecode(response.body) as Map<String, dynamic>;

    if (response.statusCode >= 400) {
      final message = body['message'] as String? ?? 'Request failed';
      final errors = (body['errors'] as List<dynamic>?)?.cast<String>();

      switch (response.statusCode) {
        case 401:
          throw AuthenticationException(message);
        case 422:
          throw ValidationException(message, errors);
        case 429:
          throw RateLimitException('Rate limit exceeded');
        default:
          throw CompliPayException(message, response.statusCode);
      }
    }

    return ApiResponse(
      success: body['success'] as bool? ?? true,
      data: body['data'] != null ? fromJson(body['data'] as Map<String, dynamic>) : null,
      message: body['message'] as String?,
      errors: (body['errors'] as List<dynamic>?)?.cast<String>(),
    );
  }

  void dispose() {
    _httpClient.close();
  }
}

// Models
class ApiResponse<T> {
  final bool success;
  final T? data;
  final String? message;
  final List<String>? errors;

  ApiResponse({required this.success, this.data, this.message, this.errors});
}

class Invoice {
  final String id;
  final String invoiceNumber;
  final String type;
  final String status;
  final String buyerName;
  final String? buyerVatNumber;
  final double subtotal;
  final double taxAmount;
  final double total;
  final String currency;
  final String? hash;
  final String? qrCode;
  final String? clearanceStatus;
  final String? reportingStatus;
  final String createdAt;

  Invoice({
    required this.id,
    required this.invoiceNumber,
    required this.type,
    required this.status,
    required this.buyerName,
    this.buyerVatNumber,
    required this.subtotal,
    required this.taxAmount,
    required this.total,
    this.currency = 'SAR',
    this.hash,
    this.qrCode,
    this.clearanceStatus,
    this.reportingStatus,
    required this.createdAt,
  });

  factory Invoice.fromJson(Map<String, dynamic> json) => Invoice(
    id: json['id'] as String,
    invoiceNumber: json['invoice_number'] as String,
    type: json['type'] as String,
    status: json['status'] as String,
    buyerName: json['buyer_name'] as String,
    buyerVatNumber: json['buyer_vat_number'] as String?,
    subtotal: (json['subtotal'] as num).toDouble(),
    taxAmount: (json['tax_amount'] as num).toDouble(),
    total: (json['total'] as num).toDouble(),
    currency: json['currency'] as String? ?? 'SAR',
    hash: json['hash'] as String?,
    qrCode: json['qr_code'] as String?,
    clearanceStatus: json['clearance_status'] as String?,
    reportingStatus: json['reporting_status'] as String?,
    createdAt: json['created_at'] as String,
  );
}

class InvoiceLine {
  final String description;
  final double quantity;
  final double unitPrice;
  final double taxRate;
  final String taxCategory;
  final String unitCode;
  final String? taxExemptionCode;
  final String? taxExemptionReason;
  final double discount;

  InvoiceLine({
    required this.description,
    required this.quantity,
    required this.unitPrice,
    this.taxRate = 15,
    this.taxCategory = 'S',
    this.unitCode = 'PCE',
    this.taxExemptionCode,
    this.taxExemptionReason,
    this.discount = 0,
  });

  Map<String, dynamic> toJson() => {
    'description': description,
    'quantity': quantity,
    'unit_price': unitPrice,
    'tax_rate': taxRate,
    'tax_category': taxCategory,
    'unit_code': unitCode,
    if (taxExemptionCode != null) 'tax_exemption_code': taxExemptionCode,
    if (taxExemptionReason != null) 'tax_exemption_reason': taxExemptionReason,
    if (discount > 0) 'discount': discount,
  };
}

class CreateInvoiceRequest {
  final String invoiceNumber;
  final String type;
  final String buyerName;
  final String? buyerVatNumber;
  final Address? buyerAddress;
  final String? issueDate;
  final String currency;
  final String paymentMeansCode;
  final double discountAmount;
  final String? notes;
  final String? billingReferenceId;
  final List<InvoiceLine> lines;

  CreateInvoiceRequest({
    required this.invoiceNumber,
    this.type = 'standard',
    required this.buyerName,
    this.buyerVatNumber,
    this.buyerAddress,
    this.issueDate,
    this.currency = 'SAR',
    this.paymentMeansCode = '10',
    this.discountAmount = 0,
    this.notes,
    this.billingReferenceId,
    required this.lines,
  });

  Map<String, dynamic> toJson() => {
    'invoice_number': invoiceNumber,
    'type': type,
    'buyer_name': buyerName,
    if (buyerVatNumber != null) 'buyer_vat_number': buyerVatNumber,
    if (buyerAddress != null) 'buyer_address': buyerAddress!.toJson(),
    if (issueDate != null) 'issue_date': issueDate,
    'currency': currency,
    'payment_means_code': paymentMeansCode,
    if (discountAmount > 0) 'discount_amount': discountAmount,
    if (notes != null) 'notes': notes,
    if (billingReferenceId != null) 'billing_reference_id': billingReferenceId,
    'lines': lines.map((l) => l.toJson()).toList(),
  };
}

class Address {
  final String street;
  final String city;
  final String postalCode;
  final String? district;
  final String countryCode;

  Address({
    required this.street,
    required this.city,
    required this.postalCode,
    this.district,
    this.countryCode = 'SA',
  });

  Map<String, dynamic> toJson() => {
    'street': street,
    'city': city,
    'postal_code': postalCode,
    if (district != null) 'district': district,
    'country_code': countryCode,
  };
}

class ZatcaResult {
  final String invoiceId;
  final String status;
  final String? hash;
  final String? qrCode;
  final String? clearanceStatus;
  final String? reportingStatus;
  final String? validationStatus;
  final List<String>? warnings;
  final List<String>? errors;

  ZatcaResult({
    required this.invoiceId,
    required this.status,
    this.hash,
    this.qrCode,
    this.clearanceStatus,
    this.reportingStatus,
    this.validationStatus,
    this.warnings,
    this.errors,
  });

  bool get isCleared => clearanceStatus == 'CLEARED';
  bool get isReported => reportingStatus == 'REPORTED';

  factory ZatcaResult.fromJson(Map<String, dynamic> json) => ZatcaResult(
    invoiceId: json['invoice_id'] as String? ?? '',
    status: json['status'] as String? ?? '',
    hash: json['hash'] as String?,
    qrCode: json['qr_code'] as String?,
    clearanceStatus: json['clearance_status'] as String?,
    reportingStatus: json['reporting_status'] as String?,
    validationStatus: json['validation_status'] as String?,
    warnings: (json['warnings'] as List<dynamic>?)?.cast<String>(),
    errors: (json['errors'] as List<dynamic>?)?.cast<String>(),
  );
}

// Resources
class InvoicesResource {
  final CompliPayClient _client;
  InvoicesResource(this._client);

  Future<ApiResponse<Invoice>> get(String invoiceId) =>
    _client.get('/v1/invoices/$invoiceId', Invoice.fromJson);

  Future<ApiResponse<Invoice>> create(CreateInvoiceRequest request) =>
    _client.post('/v1/invoices', request.toJson(), Invoice.fromJson);
}

class ComplianceResource {
  final CompliPayClient _client;
  ComplianceResource(this._client);

  Future<ApiResponse<ZatcaResult>> generate(String invoiceId) =>
    _client.post('/api/compliance/zatca/generate/$invoiceId', null, ZatcaResult.fromJson);

  Future<ApiResponse<ZatcaResult>> validate(String invoiceId) =>
    _client.post('/api/compliance/zatca/validate/$invoiceId', null, ZatcaResult.fromJson);

  Future<ApiResponse<ZatcaResult>> submit(String invoiceId) =>
    _client.post('/api/compliance/zatca/submit/$invoiceId', null, ZatcaResult.fromJson);

  Future<ApiResponse<ZatcaResult>> status(String invoiceId) =>
    _client.get('/api/compliance/zatca/status/$invoiceId', ZatcaResult.fromJson);
}

class WebhooksResource {
  final CompliPayClient _client;
  WebhooksResource(this._client);

  static const invoiceCreated = 'invoice.created';
  static const invoiceSubmitted = 'invoice.submitted';
  static const invoiceCleared = 'invoice.cleared';
  static const invoiceReported = 'invoice.reported';
  static const invoiceRejected = 'invoice.rejected';
  static const invoiceWarning = 'invoice.warning';
  static const invoiceFailed = 'invoice.failed';

  static bool verifySignature(Uint8List payload, String signature, String secret) {
    final hmacSha256 = Hmac(sha256, utf8.encode(secret));
    final digest = hmacSha256.convert(payload);
    final expected = 'sha256=${digest.toString()}';
    return expected == signature;
  }
}

// Exceptions
class CompliPayException implements Exception {
  final String message;
  final int? statusCode;
  CompliPayException(this.message, [this.statusCode]);
  @override
  String toString() => 'CompliPayException: $message';
}

class AuthenticationException extends CompliPayException {
  AuthenticationException(String message) : super(message, 401);
}

class ValidationException extends CompliPayException {
  final List<String>? errors;
  ValidationException(String message, [this.errors]) : super(message, 422);
}

class RateLimitException extends CompliPayException {
  RateLimitException(String message) : super(message, 429);
}

class NetworkException extends CompliPayException {
  NetworkException(String message) : super(message);
}

class ZatcaException extends CompliPayException {
  final List<String>? errors;
  ZatcaException(String message, [this.errors]) : super(message);
}
