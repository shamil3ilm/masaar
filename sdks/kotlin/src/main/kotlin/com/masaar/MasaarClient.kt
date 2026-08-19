package com.masaar

import kotlinx.serialization.*
import kotlinx.serialization.json.*
import java.net.URI
import java.net.http.HttpClient
import java.net.http.HttpRequest
import java.net.http.HttpResponse
import java.time.Duration
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/**
 * Masaar Kotlin SDK for ZATCA-compliant e-invoicing.
 *
 * Usage:
 * ```kotlin
 * val client = MasaarClient(
 *     baseUrl = "https://api.masaar.sa",
 *     apiKey = "your_api_key",
 *     apiSecret = "your_api_secret"
 * )
 *
 * val invoice = client.invoices.create(CreateInvoiceRequest(
 *     invoiceNumber = "INV-001",
 *     buyerName = "Acme Corp",
 *     lines = listOf(InvoiceLine(description = "Service", quantity = 1.0, unitPrice = 100.0))
 * ))
 *
 * val result = client.compliance.submit(invoice.data!!.id)
 * ```
 */
class MasaarClient(
    private val baseUrl: String,
    private val apiKey: String,
    private val apiSecret: String,
    private val timeout: Duration = Duration.ofSeconds(30)
) {
    private val httpClient = HttpClient.newBuilder()
        .connectTimeout(timeout)
        .build()

    private val json = Json {
        ignoreUnknownKeys = true
        isLenient = true
        encodeDefaults = false
    }

    val invoices = InvoicesResource(this)
    val compliance = ComplianceResource(this)
    val webhooks = WebhooksResource(this)

    internal inline fun <reified T> get(endpoint: String): ApiResponse<T> {
        return request("GET", endpoint, null)
    }

    internal inline fun <reified T> post(endpoint: String, body: Any? = null): ApiResponse<T> {
        return request("POST", endpoint, body)
    }

    internal inline fun <reified T> delete(endpoint: String): ApiResponse<T> {
        return request("DELETE", endpoint, null)
    }

    internal inline fun <reified T> request(method: String, endpoint: String, body: Any?): ApiResponse<T> {
        val requestBuilder = HttpRequest.newBuilder()
            .uri(URI.create("${baseUrl.trimEnd('/')}$endpoint"))
            .timeout(timeout)
            .header("Content-Type", "application/json")
            .header("Accept", "application/json")
            .header("X-Api-Key", apiKey)
            .header("X-Api-Secret", apiSecret)

        val httpRequest = when (method) {
            "GET" -> requestBuilder.GET().build()
            "POST" -> {
                val jsonBody = if (body != null) json.encodeToString(body) else ""
                requestBuilder.POST(HttpRequest.BodyPublishers.ofString(jsonBody)).build()
            }
            "DELETE" -> requestBuilder.DELETE().build()
            else -> throw IllegalArgumentException("Unsupported method: $method")
        }

        val response = httpClient.send(httpRequest, HttpResponse.BodyHandlers.ofString())
        return handleResponse(response)
    }

    inline fun <reified T> handleResponse(response: HttpResponse<String>): ApiResponse<T> {
        val statusCode = response.statusCode()
        val responseBody = response.body()

        if (statusCode >= 400) {
            val error = try {
                json.decodeFromString<ApiResponse<Any>>(responseBody)
            } catch (e: Exception) {
                ApiResponse<Any>(message = responseBody)
            }

            throw when (statusCode) {
                401 -> AuthenticationException(error.message ?: "Invalid credentials")
                422 -> ValidationException(error.message ?: "Validation failed", error.errors)
                429 -> RateLimitException("Rate limit exceeded")
                else -> MasaarException(error.message ?: "Request failed", statusCode)
            }
        }

        return json.decodeFromString(responseBody)
    }
}

// Models
@Serializable
data class ApiResponse<T>(
    val success: Boolean = false,
    val data: T? = null,
    val message: String? = null,
    val errors: List<String>? = null
)

@Serializable
data class Invoice(
    val id: String,
    @SerialName("invoice_number") val invoiceNumber: String,
    val type: String,
    val status: String,
    @SerialName("buyer_name") val buyerName: String,
    @SerialName("buyer_vat_number") val buyerVatNumber: String? = null,
    val subtotal: Double,
    @SerialName("tax_amount") val taxAmount: Double,
    val total: Double,
    val currency: String = "SAR",
    val hash: String? = null,
    @SerialName("qr_code") val qrCode: String? = null,
    @SerialName("clearance_status") val clearanceStatus: String? = null,
    @SerialName("reporting_status") val reportingStatus: String? = null,
    @SerialName("created_at") val createdAt: String
)

@Serializable
data class InvoiceLine(
    val description: String,
    val quantity: Double,
    @SerialName("unit_price") val unitPrice: Double,
    @SerialName("tax_rate") val taxRate: Double = 15.0,
    @SerialName("tax_category") val taxCategory: String = "S",
    @SerialName("unit_code") val unitCode: String = "PCE",
    @SerialName("tax_exemption_code") val taxExemptionCode: String? = null,
    @SerialName("tax_exemption_reason") val taxExemptionReason: String? = null,
    val discount: Double = 0.0
)

@Serializable
data class CreateInvoiceRequest(
    @SerialName("invoice_number") val invoiceNumber: String,
    val type: String = "standard",
    @SerialName("buyer_name") val buyerName: String,
    @SerialName("buyer_vat_number") val buyerVatNumber: String? = null,
    @SerialName("buyer_address") val buyerAddress: Address? = null,
    @SerialName("issue_date") val issueDate: String? = null,
    val currency: String = "SAR",
    @SerialName("payment_means_code") val paymentMeansCode: String = "10",
    @SerialName("discount_amount") val discountAmount: Double = 0.0,
    val notes: String? = null,
    @SerialName("billing_reference_id") val billingReferenceId: String? = null,
    val lines: List<InvoiceLine>
)

@Serializable
data class Address(
    val street: String,
    val city: String,
    @SerialName("postal_code") val postalCode: String,
    val district: String? = null,
    @SerialName("country_code") val countryCode: String = "SA"
)

@Serializable
data class ZatcaResult(
    @SerialName("invoice_id") val invoiceId: String = "",
    val status: String = "",
    val hash: String? = null,
    @SerialName("qr_code") val qrCode: String? = null,
    @SerialName("clearance_status") val clearanceStatus: String? = null,
    @SerialName("reporting_status") val reportingStatus: String? = null,
    @SerialName("validation_status") val validationStatus: String? = null,
    val warnings: List<String>? = null,
    val errors: List<String>? = null
) {
    val isCleared: Boolean get() = clearanceStatus == "CLEARED"
    val isReported: Boolean get() = reportingStatus == "REPORTED"
}

// Resources
class InvoicesResource(private val client: MasaarClient) {
    fun list(page: Int = 1, perPage: Int = 15): ApiResponse<List<Invoice>> =
        client.get("/v1/invoices?page=$page&per_page=$perPage")

    fun get(invoiceId: String): ApiResponse<Invoice> =
        client.get("/v1/invoices/$invoiceId")

    fun create(request: CreateInvoiceRequest): ApiResponse<Invoice> =
        client.post("/v1/invoices", request)
}

class ComplianceResource(private val client: MasaarClient) {
    fun generate(invoiceId: String): ApiResponse<ZatcaResult> =
        client.post("/api/compliance/zatca/generate/$invoiceId")

    fun validate(invoiceId: String): ApiResponse<ZatcaResult> =
        client.post("/api/compliance/zatca/validate/$invoiceId")

    fun submit(invoiceId: String): ApiResponse<ZatcaResult> =
        client.post("/api/compliance/zatca/submit/$invoiceId")

    fun status(invoiceId: String): ApiResponse<ZatcaResult> =
        client.get("/api/compliance/zatca/status/$invoiceId")
}

class WebhooksResource(private val client: MasaarClient) {
    companion object Events {
        const val INVOICE_CREATED = "invoice.created"
        const val INVOICE_SUBMITTED = "invoice.submitted"
        const val INVOICE_CLEARED = "invoice.cleared"
        const val INVOICE_REPORTED = "invoice.reported"
        const val INVOICE_REJECTED = "invoice.rejected"
        const val INVOICE_WARNING = "invoice.warning"
        const val INVOICE_FAILED = "invoice.failed"

        fun verifySignature(payload: ByteArray, signature: String, secret: String): Boolean {
            val mac = Mac.getInstance("HmacSHA256")
            mac.init(SecretKeySpec(secret.toByteArray(), "HmacSHA256"))
            val hash = mac.doFinal(payload)
            val expected = "sha256=" + hash.joinToString("") { "%02x".format(it) }
            return expected == signature
        }
    }
}

// Exceptions
open class MasaarException(message: String, val statusCode: Int = 0) : Exception(message)
class AuthenticationException(message: String) : MasaarException(message, 401)
class ValidationException(message: String, val errors: List<String>? = null) : MasaarException(message, 422)
class RateLimitException(message: String) : MasaarException(message, 429)
class ZatcaException(message: String, val errors: List<String>? = null) : MasaarException(message)
