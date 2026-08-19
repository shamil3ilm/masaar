package com.complipay.resources;

import com.complipay.CompliPayClient;
import com.complipay.exceptions.CompliPayException;
import com.complipay.models.*;

import java.util.HashMap;
import java.util.List;
import java.util.Map;

/**
 * Resource for managing invoices.
 */
public class InvoicesResource {

    private final CompliPayClient client;

    public InvoicesResource(CompliPayClient client) {
        this.client = client;
    }

    /**
     * List invoices with pagination.
     *
     * @param page Page number (default: 1)
     * @param perPage Items per page (default: 15)
     * @param status Filter by status (optional)
     * @return Paginated list of invoices
     */
    public ApiResponse<List<Invoice>> list(int page, int perPage, String status) throws CompliPayException {
        String endpoint = String.format("/v1/invoices?page=%d&per_page=%d", page, perPage);
        if (status != null && !status.isEmpty()) {
            endpoint += "&status=" + status;
        }
        return client.get(endpoint, List.class);
    }

    /**
     * List invoices with default pagination.
     */
    public ApiResponse<List<Invoice>> list() throws CompliPayException {
        return list(1, 15, null);
    }

    /**
     * Get a single invoice by ID.
     *
     * @param invoiceId The invoice UUID
     * @return The invoice
     */
    public ApiResponse<Invoice> get(String invoiceId) throws CompliPayException {
        return client.get("/v1/invoices/" + invoiceId, Invoice.class);
    }

    /**
     * Create a new invoice.
     *
     * @param request The invoice creation request
     * @return The created invoice
     */
    public ApiResponse<Invoice> create(CreateInvoiceRequest request) throws CompliPayException {
        return client.post("/v1/invoices", request, Invoice.class);
    }

    /**
     * Create a credit note (must reference an original invoice).
     *
     * @param invoiceNumber The credit note number
     * @param buyerName The buyer name
     * @param billingReferenceId The original invoice ID being credited
     * @param lines The line items
     * @return The created credit note
     */
    public ApiResponse<Invoice> createCreditNote(
            String invoiceNumber,
            String buyerName,
            String billingReferenceId,
            List<InvoiceLine> lines
    ) throws CompliPayException {
        CreateInvoiceRequest request = CreateInvoiceRequest.builder()
                .invoiceNumber(invoiceNumber)
                .buyerName(buyerName)
                .creditNote()
                .billingReferenceId(billingReferenceId)
                .lines(lines)
                .build();
        return create(request);
    }

    /**
     * Create a debit note (must reference an original invoice).
     *
     * @param invoiceNumber The debit note number
     * @param buyerName The buyer name
     * @param billingReferenceId The original invoice ID being debited
     * @param lines The line items
     * @return The created debit note
     */
    public ApiResponse<Invoice> createDebitNote(
            String invoiceNumber,
            String buyerName,
            String billingReferenceId,
            List<InvoiceLine> lines
    ) throws CompliPayException {
        CreateInvoiceRequest request = CreateInvoiceRequest.builder()
                .invoiceNumber(invoiceNumber)
                .buyerName(buyerName)
                .debitNote()
                .billingReferenceId(billingReferenceId)
                .lines(lines)
                .build();
        return create(request);
    }
}
