package com.masaar;

import com.masaar.exceptions.*;
import com.masaar.models.*;
import com.masaar.resources.*;

import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;
import java.util.Map;
import java.util.Objects;

import com.google.gson.Gson;
import com.google.gson.GsonBuilder;

/**
 * Masaar Java SDK
 *
 * ZATCA-compliant e-invoicing API client for Java 11+
 * Works with Spring Boot, Jakarta EE, Micronaut, Quarkus, or any Java application.
 *
 * <pre>{@code
 * MasaarClient client = new MasaarClient.Builder()
 *     .baseUrl("https://api.masaar.sa")
 *     .apiKey("your_api_key")
 *     .apiSecret("your_api_secret")
 *     .build();
 *
 * // Create invoice
 * Invoice invoice = client.invoices().create(CreateInvoiceRequest.builder()
 *     .invoiceNumber("INV-001")
 *     .buyerName("Acme Corp")
 *     .buyerVatNumber("300000000000003")
 *     .addLine(InvoiceLine.builder()
 *         .description("Consulting Services")
 *         .quantity(10)
 *         .unitPrice(100.00)
 *         .taxRate(15.0)
 *         .build())
 *     .build());
 *
 * // Submit to ZATCA
 * ZatcaResult result = client.compliance().submit(invoice.getId());
 * }</pre>
 *
 * @author Masaar
 * @version 1.0.0
 */
public class MasaarClient {

    private final String baseUrl;
    private final String apiKey;
    private final String apiSecret;
    private final HttpClient httpClient;
    private final Gson gson;
    private final Duration timeout;

    // Resources
    private final InvoicesResource invoices;
    private final ComplianceResource compliance;
    private final WebhooksResource webhooks;

    private MasaarClient(Builder builder) {
        this.baseUrl = Objects.requireNonNull(builder.baseUrl, "baseUrl is required")
                .replaceAll("/$", "");
        this.apiKey = builder.apiKey;
        this.apiSecret = builder.apiSecret;
        this.timeout = builder.timeout != null ? builder.timeout : Duration.ofSeconds(30);

        if (apiKey == null && builder.jwtToken == null) {
            throw new IllegalArgumentException("Either apiKey or jwtToken must be provided");
        }

        this.httpClient = HttpClient.newBuilder()
                .connectTimeout(timeout)
                .build();

        this.gson = new GsonBuilder()
                .setDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSSZ")
                .create();

        // Initialize resources
        this.invoices = new InvoicesResource(this);
        this.compliance = new ComplianceResource(this);
        this.webhooks = new WebhooksResource(this);
    }

    /**
     * Get the invoices resource for managing invoices.
     */
    public InvoicesResource invoices() {
        return invoices;
    }

    /**
     * Get the compliance resource for ZATCA operations.
     */
    public ComplianceResource compliance() {
        return compliance;
    }

    /**
     * Get the webhooks resource for webhook management.
     */
    public WebhooksResource webhooks() {
        return webhooks;
    }

    /**
     * Check API health status.
     */
    public ApiResponse<Map<String, Object>> health() throws MasaarException {
        return get("/api/health", Map.class);
    }

    // HTTP methods for internal use

    public <T> ApiResponse<T> get(String endpoint, Class<T> responseType) throws MasaarException {
        return request("GET", endpoint, null, responseType);
    }

    public <T> ApiResponse<T> post(String endpoint, Object body, Class<T> responseType) throws MasaarException {
        return request("POST", endpoint, body, responseType);
    }

    public <T> ApiResponse<T> put(String endpoint, Object body, Class<T> responseType) throws MasaarException {
        return request("PUT", endpoint, body, responseType);
    }

    public <T> ApiResponse<T> delete(String endpoint, Class<T> responseType) throws MasaarException {
        return request("DELETE", endpoint, null, responseType);
    }

    @SuppressWarnings("unchecked")
    private <T> ApiResponse<T> request(String method, String endpoint, Object body, Class<T> responseType)
            throws MasaarException {
        try {
            HttpRequest.Builder requestBuilder = HttpRequest.newBuilder()
                    .uri(URI.create(baseUrl + endpoint))
                    .timeout(timeout)
                    .header("Content-Type", "application/json")
                    .header("Accept", "application/json");

            // Add authentication headers
            if (apiKey != null) {
                requestBuilder.header("X-Api-Key", apiKey);
            }
            if (apiSecret != null) {
                requestBuilder.header("X-Api-Secret", apiSecret);
            }

            // Set method and body
            if (body != null) {
                String jsonBody = gson.toJson(body);
                requestBuilder.method(method, HttpRequest.BodyPublishers.ofString(jsonBody));
            } else {
                requestBuilder.method(method, HttpRequest.BodyPublishers.noBody());
            }

            HttpResponse<String> response = httpClient.send(
                    requestBuilder.build(),
                    HttpResponse.BodyHandlers.ofString()
            );

            return handleResponse(response, responseType);

        } catch (IOException e) {
            throw new NetworkException("Network error: " + e.getMessage(), e);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            throw new NetworkException("Request interrupted", e);
        }
    }

    @SuppressWarnings("unchecked")
    private <T> ApiResponse<T> handleResponse(HttpResponse<String> response, Class<T> responseType)
            throws MasaarException {
        int statusCode = response.statusCode();
        String responseBody = response.body();

        // Parse response
        ApiResponse<T> apiResponse;
        try {
            if (responseType == null || responseType == Void.class) {
                apiResponse = new ApiResponse<>();
            } else {
                apiResponse = gson.fromJson(responseBody,
                        ApiResponse.class);
                if (apiResponse.getData() != null && responseType != Map.class) {
                    // Re-parse data with correct type
                    String dataJson = gson.toJson(apiResponse.getData());
                    T data = gson.fromJson(dataJson, responseType);
                    apiResponse.setData(data);
                }
            }
        } catch (Exception e) {
            apiResponse = new ApiResponse<>();
            apiResponse.setMessage(responseBody);
        }

        // Handle error responses
        if (statusCode == 401) {
            throw new AuthenticationException("Invalid API key or secret");
        }

        if (statusCode == 422) {
            throw new ValidationException(
                    apiResponse.getMessage() != null ? apiResponse.getMessage() : "Validation failed",
                    apiResponse.getErrors()
            );
        }

        if (statusCode == 429) {
            throw new RateLimitException("Rate limit exceeded");
        }

        if (statusCode >= 400) {
            throw new MasaarException(
                    apiResponse.getMessage() != null ? apiResponse.getMessage() : "Request failed",
                    statusCode,
                    apiResponse.getErrors()
            );
        }

        apiResponse.setSuccess(true);
        return apiResponse;
    }

    public Gson getGson() {
        return gson;
    }

    /**
     * Builder for MasaarClient.
     */
    public static class Builder {
        private String baseUrl;
        private String apiKey;
        private String apiSecret;
        private String jwtToken;
        private Duration timeout;

        public Builder baseUrl(String baseUrl) {
            this.baseUrl = baseUrl;
            return this;
        }

        public Builder apiKey(String apiKey) {
            this.apiKey = apiKey;
            return this;
        }

        public Builder apiSecret(String apiSecret) {
            this.apiSecret = apiSecret;
            return this;
        }

        public Builder jwtToken(String jwtToken) {
            this.jwtToken = jwtToken;
            return this;
        }

        public Builder timeout(Duration timeout) {
            this.timeout = timeout;
            return this;
        }

        public MasaarClient build() {
            return new MasaarClient(this);
        }
    }
}
