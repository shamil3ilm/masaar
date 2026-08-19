package com.masaar.resources;

import com.masaar.MasaarClient;
import com.masaar.exceptions.MasaarException;
import com.masaar.exceptions.ZatcaException;
import com.masaar.models.ApiResponse;
import com.masaar.models.ZatcaResult;

/**
 * Resource for ZATCA compliance operations.
 */
public class ComplianceResource {

    private final MasaarClient client;

    public ComplianceResource(MasaarClient client) {
        this.client = client;
    }

    /**
     * Generate compliance data (hash, QR code, signed XML) for an invoice.
     * This prepares the invoice for ZATCA submission without actually submitting.
     *
     * @param invoiceId The invoice UUID
     * @return The generated compliance data
     */
    public ApiResponse<ZatcaResult> generate(String invoiceId) throws MasaarException {
        return client.post("/api/compliance/zatca/generate/" + invoiceId, null, ZatcaResult.class);
    }

    /**
     * Validate an invoice against ZATCA rules without submitting.
     * Use this to check for errors before actual submission.
     *
     * @param invoiceId The invoice UUID
     * @return Validation result with any warnings or errors
     */
    public ApiResponse<ZatcaResult> validate(String invoiceId) throws MasaarException {
        return client.post("/api/compliance/zatca/validate/" + invoiceId, null, ZatcaResult.class);
    }

    /**
     * Submit an invoice to ZATCA for clearance (B2B) or reporting (B2C).
     *
     * For B2B invoices (standard invoices with buyer VAT number):
     * - Invoice is sent for real-time clearance
     * - Must be cleared before providing to customer
     *
     * For B2C invoices (simplified invoices):
     * - Invoice is reported within 24 hours
     * - Can be provided to customer immediately
     *
     * @param invoiceId The invoice UUID
     * @return The submission result with ZATCA response
     * @throws ZatcaException if ZATCA rejects the invoice
     */
    public ApiResponse<ZatcaResult> submit(String invoiceId) throws MasaarException {
        ApiResponse<ZatcaResult> result = client.post(
                "/api/compliance/zatca/submit/" + invoiceId,
                null,
                ZatcaResult.class
        );

        if (!result.isSuccess()) {
            throw new ZatcaException("ZATCA submission failed", result.getErrors());
        }

        return result;
    }

    /**
     * Get the current ZATCA compliance status for an invoice.
     *
     * @param invoiceId The invoice UUID
     * @return Current compliance status
     */
    public ApiResponse<ZatcaResult> status(String invoiceId) throws MasaarException {
        return client.get("/api/compliance/zatca/status/" + invoiceId, ZatcaResult.class);
    }

    /**
     * Submit an invoice asynchronously (for high-volume scenarios).
     * Returns immediately and processes in background.
     * Use webhooks or polling to track completion.
     *
     * @param invoiceId The invoice UUID
     * @return Submission acknowledgment with tracking ID
     */
    public ApiResponse<ZatcaResult> submitAsync(String invoiceId) throws MasaarException {
        return client.post("/api/compliance/zatca/submit-async/" + invoiceId, null, ZatcaResult.class);
    }
}
