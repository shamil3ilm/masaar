package com.complipay.resources;

import com.complipay.CompliPayClient;
import com.complipay.exceptions.CompliPayException;
import com.complipay.models.ApiResponse;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.nio.charset.StandardCharsets;
import java.security.InvalidKeyException;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.List;
import java.util.Map;

/**
 * Resource for managing webhooks.
 */
public class WebhooksResource {

    private final CompliPayClient client;

    public WebhooksResource(CompliPayClient client) {
        this.client = client;
    }

    /**
     * List all registered webhooks.
     *
     * @return List of webhooks
     */
    public ApiResponse<List<Map<String, Object>>> list() throws CompliPayException {
        return client.get("/api/webhooks", List.class);
    }

    /**
     * Create a new webhook subscription.
     *
     * @param url The URL to receive webhook events
     * @param events List of events to subscribe to
     * @param secret Optional secret for signature verification
     * @return The created webhook
     */
    public ApiResponse<Map<String, Object>> create(String url, List<String> events, String secret)
            throws CompliPayException {
        Map<String, Object> body = Map.of(
                "url", url,
                "events", events,
                "secret", secret != null ? secret : ""
        );
        return client.post("/api/webhooks", body, Map.class);
    }

    /**
     * Create a webhook without a custom secret (one will be generated).
     */
    public ApiResponse<Map<String, Object>> create(String url, List<String> events) throws CompliPayException {
        return create(url, events, null);
    }

    /**
     * Delete a webhook subscription.
     *
     * @param webhookId The webhook ID
     */
    public ApiResponse<Void> delete(String webhookId) throws CompliPayException {
        return client.delete("/api/webhooks/" + webhookId, Void.class);
    }

    /**
     * Verify a webhook signature.
     *
     * Use this to verify that webhook payloads are authentic and weren't tampered with.
     *
     * @param payload The raw request body bytes
     * @param signature The X-CompliPay-Signature header value
     * @param secret Your webhook secret
     * @return true if the signature is valid
     */
    public static boolean verifySignature(byte[] payload, String signature, String secret) {
        try {
            Mac hmac = Mac.getInstance("HmacSHA256");
            SecretKeySpec secretKey = new SecretKeySpec(
                    secret.getBytes(StandardCharsets.UTF_8),
                    "HmacSHA256"
            );
            hmac.init(secretKey);

            byte[] hash = hmac.doFinal(payload);
            String expected = "sha256=" + bytesToHex(hash);

            return MessageDigest.isEqual(
                    expected.getBytes(StandardCharsets.UTF_8),
                    signature.getBytes(StandardCharsets.UTF_8)
            );
        } catch (NoSuchAlgorithmException | InvalidKeyException e) {
            return false;
        }
    }

    /**
     * Verify a webhook signature from string payload.
     */
    public static boolean verifySignature(String payload, String signature, String secret) {
        return verifySignature(payload.getBytes(StandardCharsets.UTF_8), signature, secret);
    }

    private static String bytesToHex(byte[] bytes) {
        StringBuilder hexString = new StringBuilder();
        for (byte b : bytes) {
            String hex = Integer.toHexString(0xff & b);
            if (hex.length() == 1) {
                hexString.append('0');
            }
            hexString.append(hex);
        }
        return hexString.toString();
    }

    /**
     * Available webhook events.
     */
    public static class Events {
        public static final String INVOICE_CREATED = "invoice.created";
        public static final String INVOICE_SUBMITTED = "invoice.submitted";
        public static final String INVOICE_CLEARED = "invoice.cleared";
        public static final String INVOICE_REPORTED = "invoice.reported";
        public static final String INVOICE_REJECTED = "invoice.rejected";
        public static final String INVOICE_WARNING = "invoice.warning";
        public static final String INVOICE_FAILED = "invoice.failed";

        public static final List<String> ALL = List.of(
                INVOICE_CREATED,
                INVOICE_SUBMITTED,
                INVOICE_CLEARED,
                INVOICE_REPORTED,
                INVOICE_REJECTED,
                INVOICE_WARNING,
                INVOICE_FAILED
        );
    }
}
