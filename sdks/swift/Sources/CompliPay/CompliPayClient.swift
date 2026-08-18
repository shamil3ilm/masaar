import Foundation
import CryptoKit

/// CompliPay Swift SDK for ZATCA-compliant e-invoicing.
///
/// Usage:
/// ```swift
/// let client = CompliPayClient(
///     baseURL: "https://api.masaar.sa",
///     apiKey: "your_api_key",
///     apiSecret: "your_api_secret"
/// )
///
/// let invoice = try await client.invoices.create(CreateInvoiceRequest(
///     invoiceNumber: "INV-001",
///     buyerName: "Acme Corp",
///     lines: [InvoiceLine(description: "Service", quantity: 1, unitPrice: 100)]
/// ))
///
/// let result = try await client.compliance.submit(invoice.data!.id)
/// ```

// MARK: - Client

public actor CompliPayClient {
    private let baseURL: String
    private let apiKey: String
    private let apiSecret: String
    private let session: URLSession
    private let encoder: JSONEncoder
    private let decoder: JSONDecoder

    public let invoices: InvoicesResource
    public let compliance: ComplianceResource
    public let webhooks: WebhooksResource

    public init(baseURL: String, apiKey: String, apiSecret: String, timeout: TimeInterval = 30) {
        self.baseURL = baseURL.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        self.apiKey = apiKey
        self.apiSecret = apiSecret

        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = timeout
        self.session = URLSession(configuration: config)

        self.encoder = JSONEncoder()
        encoder.keyEncodingStrategy = .convertToSnakeCase

        self.decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase

        self.invoices = InvoicesResource(client: self)
        self.compliance = ComplianceResource(client: self)
        self.webhooks = WebhooksResource(client: self)
    }

    internal func get<T: Decodable>(_ endpoint: String) async throws -> ApiResponse<T> {
        return try await request("GET", endpoint, body: nil as Empty?)
    }

    internal func post<T: Decodable, B: Encodable>(_ endpoint: String, body: B?) async throws -> ApiResponse<T> {
        return try await request("POST", endpoint, body: body)
    }

    internal func delete<T: Decodable>(_ endpoint: String) async throws -> ApiResponse<T> {
        return try await request("DELETE", endpoint, body: nil as Empty?)
    }

    private func request<T: Decodable, B: Encodable>(_ method: String, _ endpoint: String, body: B?) async throws -> ApiResponse<T> {
        guard let url = URL(string: "\(baseURL)\(endpoint)") else {
            throw CompliPayError.invalidURL
        }

        var request = URLRequest(url: url)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(apiKey, forHTTPHeaderField: "X-Api-Key")
        request.setValue(apiSecret, forHTTPHeaderField: "X-Api-Secret")

        if let body = body {
            request.httpBody = try encoder.encode(body)
        }

        let (data, response) = try await session.data(for: request)

        guard let httpResponse = response as? HTTPURLResponse else {
            throw CompliPayError.invalidResponse
        }

        if httpResponse.statusCode >= 400 {
            let errorResponse = try? decoder.decode(ApiResponse<Empty>.self, from: data)
            let message = errorResponse?.message ?? "Request failed"

            switch httpResponse.statusCode {
            case 401: throw CompliPayError.authentication(message)
            case 422: throw CompliPayError.validation(message, errorResponse?.errors)
            case 429: throw CompliPayError.rateLimit
            default: throw CompliPayError.api(message, httpResponse.statusCode)
            }
        }

        return try decoder.decode(ApiResponse<T>.self, from: data)
    }
}

private struct Empty: Codable {}

// MARK: - Models

public struct ApiResponse<T: Decodable>: Decodable {
    public let success: Bool
    public let data: T?
    public let message: String?
    public let errors: [String]?
}

public struct Invoice: Codable {
    public let id: String
    public let invoiceNumber: String
    public let type: String
    public let status: String
    public let buyerName: String
    public let buyerVatNumber: String?
    public let subtotal: Double
    public let taxAmount: Double
    public let total: Double
    public let currency: String
    public let hash: String?
    public let qrCode: String?
    public let clearanceStatus: String?
    public let reportingStatus: String?
    public let createdAt: String
}

public struct InvoiceLine: Codable {
    public let description: String
    public let quantity: Double
    public let unitPrice: Double
    public var taxRate: Double = 15
    public var taxCategory: String = "S"
    public var unitCode: String = "PCE"
    public var taxExemptionCode: String?
    public var taxExemptionReason: String?
    public var discount: Double = 0

    public init(description: String, quantity: Double, unitPrice: Double,
                taxRate: Double = 15, taxCategory: String = "S", unitCode: String = "PCE",
                taxExemptionCode: String? = nil, taxExemptionReason: String? = nil, discount: Double = 0) {
        self.description = description
        self.quantity = quantity
        self.unitPrice = unitPrice
        self.taxRate = taxRate
        self.taxCategory = taxCategory
        self.unitCode = unitCode
        self.taxExemptionCode = taxExemptionCode
        self.taxExemptionReason = taxExemptionReason
        self.discount = discount
    }
}

public struct CreateInvoiceRequest: Codable {
    public let invoiceNumber: String
    public var type: String = "standard"
    public let buyerName: String
    public var buyerVatNumber: String?
    public var buyerAddress: Address?
    public var issueDate: String?
    public var currency: String = "SAR"
    public var paymentMeansCode: String = "10"
    public var discountAmount: Double = 0
    public var notes: String?
    public var billingReferenceId: String?
    public let lines: [InvoiceLine]

    public init(invoiceNumber: String, buyerName: String, lines: [InvoiceLine],
                type: String = "standard", buyerVatNumber: String? = nil,
                buyerAddress: Address? = nil, currency: String = "SAR") {
        self.invoiceNumber = invoiceNumber
        self.buyerName = buyerName
        self.lines = lines
        self.type = type
        self.buyerVatNumber = buyerVatNumber
        self.buyerAddress = buyerAddress
        self.currency = currency
    }
}

public struct Address: Codable {
    public let street: String
    public let city: String
    public let postalCode: String
    public var district: String?
    public var countryCode: String = "SA"

    public init(street: String, city: String, postalCode: String, district: String? = nil, countryCode: String = "SA") {
        self.street = street
        self.city = city
        self.postalCode = postalCode
        self.district = district
        self.countryCode = countryCode
    }
}

public struct ZatcaResult: Codable {
    public let invoiceId: String?
    public let status: String?
    public let hash: String?
    public let qrCode: String?
    public let clearanceStatus: String?
    public let reportingStatus: String?
    public let validationStatus: String?
    public let warnings: [String]?
    public let errors: [String]?

    public var isCleared: Bool { clearanceStatus == "CLEARED" }
    public var isReported: Bool { reportingStatus == "REPORTED" }
}

// MARK: - Resources

public struct InvoicesResource {
    let client: CompliPayClient

    public func get(_ invoiceId: String) async throws -> ApiResponse<Invoice> {
        try await client.get("/v1/invoices/\(invoiceId)")
    }

    public func create(_ request: CreateInvoiceRequest) async throws -> ApiResponse<Invoice> {
        try await client.post("/v1/invoices", body: request)
    }
}

public struct ComplianceResource {
    let client: CompliPayClient

    public func generate(_ invoiceId: String) async throws -> ApiResponse<ZatcaResult> {
        try await client.post("/api/compliance/zatca/generate/\(invoiceId)", body: nil as Empty?)
    }

    public func validate(_ invoiceId: String) async throws -> ApiResponse<ZatcaResult> {
        try await client.post("/api/compliance/zatca/validate/\(invoiceId)", body: nil as Empty?)
    }

    public func submit(_ invoiceId: String) async throws -> ApiResponse<ZatcaResult> {
        try await client.post("/api/compliance/zatca/submit/\(invoiceId)", body: nil as Empty?)
    }

    public func status(_ invoiceId: String) async throws -> ApiResponse<ZatcaResult> {
        try await client.get("/api/compliance/zatca/status/\(invoiceId)")
    }
}

public struct WebhooksResource {
    let client: CompliPayClient

    public static let invoiceCreated = "invoice.created"
    public static let invoiceSubmitted = "invoice.submitted"
    public static let invoiceCleared = "invoice.cleared"
    public static let invoiceReported = "invoice.reported"
    public static let invoiceRejected = "invoice.rejected"
    public static let invoiceWarning = "invoice.warning"
    public static let invoiceFailed = "invoice.failed"

    public static func verifySignature(payload: Data, signature: String, secret: String) -> Bool {
        let key = SymmetricKey(data: Data(secret.utf8))
        let hmac = HMAC<SHA256>.authenticationCode(for: payload, using: key)
        let expected = "sha256=" + hmac.map { String(format: "%02x", $0) }.joined()
        return expected == signature
    }
}

// MARK: - Errors

public enum CompliPayError: Error, LocalizedError {
    case invalidURL
    case invalidResponse
    case authentication(String)
    case validation(String, [String]?)
    case rateLimit
    case api(String, Int)
    case zatca(String, [String]?)

    public var errorDescription: String? {
        switch self {
        case .invalidURL: return "Invalid URL"
        case .invalidResponse: return "Invalid response"
        case .authentication(let msg): return "Authentication failed: \(msg)"
        case .validation(let msg, _): return "Validation failed: \(msg)"
        case .rateLimit: return "Rate limit exceeded"
        case .api(let msg, let code): return "API error (\(code)): \(msg)"
        case .zatca(let msg, _): return "ZATCA error: \(msg)"
        }
    }
}
