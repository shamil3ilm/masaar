package com.masaar.models;

import com.google.gson.annotations.SerializedName;
import java.util.List;

/**
 * Result of a ZATCA submission or validation operation.
 */
public class ZatcaResult {

    @SerializedName("invoice_id")
    private String invoiceId;

    private String status;
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

    @SerializedName("validation_status")
    private String validationStatus;

    private List<String> warnings;
    private List<String> errors;

    @SerializedName("zatca_response")
    private ZatcaResponse zatcaResponse;

    // Convenience methods

    public boolean isCleared() {
        return "CLEARED".equals(clearanceStatus);
    }

    public boolean isReported() {
        return "REPORTED".equals(reportingStatus);
    }

    public boolean hasWarnings() {
        return warnings != null && !warnings.isEmpty();
    }

    public boolean hasErrors() {
        return errors != null && !errors.isEmpty();
    }

    // Getters and Setters

    public String getInvoiceId() {
        return invoiceId;
    }

    public void setInvoiceId(String invoiceId) {
        this.invoiceId = invoiceId;
    }

    public String getStatus() {
        return status;
    }

    public void setStatus(String status) {
        this.status = status;
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

    public String getValidationStatus() {
        return validationStatus;
    }

    public void setValidationStatus(String validationStatus) {
        this.validationStatus = validationStatus;
    }

    public List<String> getWarnings() {
        return warnings;
    }

    public void setWarnings(List<String> warnings) {
        this.warnings = warnings;
    }

    public List<String> getErrors() {
        return errors;
    }

    public void setErrors(List<String> errors) {
        this.errors = errors;
    }

    public ZatcaResponse getZatcaResponse() {
        return zatcaResponse;
    }

    public void setZatcaResponse(ZatcaResponse zatcaResponse) {
        this.zatcaResponse = zatcaResponse;
    }

    /**
     * Raw ZATCA response details.
     */
    public static class ZatcaResponse {

        @SerializedName("clearance_status")
        private String clearanceStatus;

        @SerializedName("reporting_status")
        private String reportingStatus;

        @SerializedName("validation_status")
        private String validationStatus;

        @SerializedName("validation_results")
        private ValidationResults validationResults;

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

        public String getValidationStatus() {
            return validationStatus;
        }

        public void setValidationStatus(String validationStatus) {
            this.validationStatus = validationStatus;
        }

        public ValidationResults getValidationResults() {
            return validationResults;
        }

        public void setValidationResults(ValidationResults validationResults) {
            this.validationResults = validationResults;
        }
    }

    /**
     * ZATCA validation results.
     */
    public static class ValidationResults {

        @SerializedName("info_messages")
        private List<ValidationMessage> infoMessages;

        @SerializedName("warning_messages")
        private List<ValidationMessage> warningMessages;

        @SerializedName("error_messages")
        private List<ValidationMessage> errorMessages;

        public List<ValidationMessage> getInfoMessages() {
            return infoMessages;
        }

        public void setInfoMessages(List<ValidationMessage> infoMessages) {
            this.infoMessages = infoMessages;
        }

        public List<ValidationMessage> getWarningMessages() {
            return warningMessages;
        }

        public void setWarningMessages(List<ValidationMessage> warningMessages) {
            this.warningMessages = warningMessages;
        }

        public List<ValidationMessage> getErrorMessages() {
            return errorMessages;
        }

        public void setErrorMessages(List<ValidationMessage> errorMessages) {
            this.errorMessages = errorMessages;
        }
    }

    /**
     * Individual validation message from ZATCA.
     */
    public static class ValidationMessage {
        private String type;
        private String code;
        private String category;
        private String message;
        private String status;

        public String getType() {
            return type;
        }

        public void setType(String type) {
            this.type = type;
        }

        public String getCode() {
            return code;
        }

        public void setCode(String code) {
            this.code = code;
        }

        public String getCategory() {
            return category;
        }

        public void setCategory(String category) {
            this.category = category;
        }

        public String getMessage() {
            return message;
        }

        public void setMessage(String message) {
            this.message = message;
        }

        public String getStatus() {
            return status;
        }

        public void setStatus(String status) {
            this.status = status;
        }
    }
}
