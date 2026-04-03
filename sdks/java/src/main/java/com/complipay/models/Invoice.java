package com.complipay.models;

import com.google.gson.annotations.SerializedName;
import java.math.BigDecimal;
import java.time.LocalDateTime;
import java.util.List;

/**
 * Invoice model representing a ZATCA-compliant invoice.
 */
public class Invoice {

    private String id;

    @SerializedName("invoice_number")
    private String invoiceNumber;

    private String type;
    private String status;

    @SerializedName("buyer_name")
    private String buyerName;

    @SerializedName("buyer_vat_number")
    private String buyerVatNumber;

    private BigDecimal subtotal;

    @SerializedName("tax_amount")
    private BigDecimal taxAmount;

    private BigDecimal total;
    private String currency;
    private String hash;

    @SerializedName("qr_code")
    private String qrCode;

    @SerializedName("signed_xml")
    private String signedXml;

    @SerializedName("zatca_uuid")
    private String zatcaUuid;

    @SerializedName("clearance_status")
    private String clearanceStatus;

    @SerializedName("reporting_status")
    private String reportingStatus;

    private List<InvoiceLine> lines;

    @SerializedName("created_at")
    private String createdAt;

    @SerializedName("updated_at")
    private String updatedAt;

    // Getters and Setters

    public String getId() {
        return id;
    }

    public void setId(String id) {
        this.id = id;
    }

    public String getInvoiceNumber() {
        return invoiceNumber;
    }

    public void setInvoiceNumber(String invoiceNumber) {
        this.invoiceNumber = invoiceNumber;
    }

    public String getType() {
        return type;
    }

    public void setType(String type) {
        this.type = type;
    }

    public String getStatus() {
        return status;
    }

    public void setStatus(String status) {
        this.status = status;
    }

    public String getBuyerName() {
        return buyerName;
    }

    public void setBuyerName(String buyerName) {
        this.buyerName = buyerName;
    }

    public String getBuyerVatNumber() {
        return buyerVatNumber;
    }

    public void setBuyerVatNumber(String buyerVatNumber) {
        this.buyerVatNumber = buyerVatNumber;
    }

    public BigDecimal getSubtotal() {
        return subtotal;
    }

    public void setSubtotal(BigDecimal subtotal) {
        this.subtotal = subtotal;
    }

    public BigDecimal getTaxAmount() {
        return taxAmount;
    }

    public void setTaxAmount(BigDecimal taxAmount) {
        this.taxAmount = taxAmount;
    }

    public BigDecimal getTotal() {
        return total;
    }

    public void setTotal(BigDecimal total) {
        this.total = total;
    }

    public String getCurrency() {
        return currency;
    }

    public void setCurrency(String currency) {
        this.currency = currency;
    }

    public String getHash() {
        return hash;
    }

    public void setHash(String hash) {
        this.hash = hash;
    }

    public String getQrCode() {
        return qrCode;
    }

    public void setQrCode(String qrCode) {
        this.qrCode = qrCode;
    }

    public String getSignedXml() {
        return signedXml;
    }

    public void setSignedXml(String signedXml) {
        this.signedXml = signedXml;
    }

    public String getZatcaUuid() {
        return zatcaUuid;
    }

    public void setZatcaUuid(String zatcaUuid) {
        this.zatcaUuid = zatcaUuid;
    }

    public String getClearanceStatus() {
        return clearanceStatus;
    }

    public void setClearanceStatus(String clearanceStatus) {
        this.clearanceStatus = clearanceStatus;
    }

    public String getReportingStatus() {
        return reportingStatus;
    }

    public void setReportingStatus(String reportingStatus) {
        this.reportingStatus = reportingStatus;
    }

    public List<InvoiceLine> getLines() {
        return lines;
    }

    public void setLines(List<InvoiceLine> lines) {
        this.lines = lines;
    }

    public String getCreatedAt() {
        return createdAt;
    }

    public void setCreatedAt(String createdAt) {
        this.createdAt = createdAt;
    }

    public String getUpdatedAt() {
        return updatedAt;
    }

    public void setUpdatedAt(String updatedAt) {
        this.updatedAt = updatedAt;
    }
}
