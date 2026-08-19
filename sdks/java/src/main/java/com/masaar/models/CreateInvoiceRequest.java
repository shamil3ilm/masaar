package com.masaar.models;

import com.google.gson.annotations.SerializedName;
import java.math.BigDecimal;
import java.util.ArrayList;
import java.util.List;

/**
 * Request object for creating a new invoice.
 */
public class CreateInvoiceRequest {

    @SerializedName("invoice_number")
    private String invoiceNumber;

    private String type;

    @SerializedName("buyer_name")
    private String buyerName;

    @SerializedName("buyer_vat_number")
    private String buyerVatNumber;

    @SerializedName("buyer_address")
    private BuyerAddress buyerAddress;

    @SerializedName("issue_date")
    private String issueDate;

    private String currency;

    @SerializedName("payment_means_code")
    private String paymentMeansCode;

    @SerializedName("discount_amount")
    private BigDecimal discountAmount;

    private String notes;

    @SerializedName("billing_reference_id")
    private String billingReferenceId;

    private List<InvoiceLine> lines;

    public static Builder builder() {
        return new Builder();
    }

    // Getters and Setters

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

    public BuyerAddress getBuyerAddress() {
        return buyerAddress;
    }

    public void setBuyerAddress(BuyerAddress buyerAddress) {
        this.buyerAddress = buyerAddress;
    }

    public String getIssueDate() {
        return issueDate;
    }

    public void setIssueDate(String issueDate) {
        this.issueDate = issueDate;
    }

    public String getCurrency() {
        return currency;
    }

    public void setCurrency(String currency) {
        this.currency = currency;
    }

    public String getPaymentMeansCode() {
        return paymentMeansCode;
    }

    public void setPaymentMeansCode(String paymentMeansCode) {
        this.paymentMeansCode = paymentMeansCode;
    }

    public BigDecimal getDiscountAmount() {
        return discountAmount;
    }

    public void setDiscountAmount(BigDecimal discountAmount) {
        this.discountAmount = discountAmount;
    }

    public String getNotes() {
        return notes;
    }

    public void setNotes(String notes) {
        this.notes = notes;
    }

    public String getBillingReferenceId() {
        return billingReferenceId;
    }

    public void setBillingReferenceId(String billingReferenceId) {
        this.billingReferenceId = billingReferenceId;
    }

    public List<InvoiceLine> getLines() {
        return lines;
    }

    public void setLines(List<InvoiceLine> lines) {
        this.lines = lines;
    }

    /**
     * Buyer address for B2B invoices.
     */
    public static class BuyerAddress {
        private String street;
        private String city;

        @SerializedName("postal_code")
        private String postalCode;

        private String district;

        @SerializedName("country_code")
        private String countryCode;

        public static Builder builder() {
            return new Builder();
        }

        // Getters and Setters

        public String getStreet() {
            return street;
        }

        public void setStreet(String street) {
            this.street = street;
        }

        public String getCity() {
            return city;
        }

        public void setCity(String city) {
            this.city = city;
        }

        public String getPostalCode() {
            return postalCode;
        }

        public void setPostalCode(String postalCode) {
            this.postalCode = postalCode;
        }

        public String getDistrict() {
            return district;
        }

        public void setDistrict(String district) {
            this.district = district;
        }

        public String getCountryCode() {
            return countryCode;
        }

        public void setCountryCode(String countryCode) {
            this.countryCode = countryCode;
        }

        public static class Builder {
            private final BuyerAddress address = new BuyerAddress();

            public Builder street(String street) {
                address.street = street;
                return this;
            }

            public Builder city(String city) {
                address.city = city;
                return this;
            }

            public Builder postalCode(String postalCode) {
                address.postalCode = postalCode;
                return this;
            }

            public Builder district(String district) {
                address.district = district;
                return this;
            }

            public Builder countryCode(String countryCode) {
                address.countryCode = countryCode;
                return this;
            }

            public BuyerAddress build() {
                if (address.countryCode == null) {
                    address.countryCode = "SA";
                }
                return address;
            }
        }
    }

    /**
     * Builder for CreateInvoiceRequest.
     */
    public static class Builder {
        private final CreateInvoiceRequest request = new CreateInvoiceRequest();

        public Builder() {
            request.lines = new ArrayList<>();
        }

        public Builder invoiceNumber(String invoiceNumber) {
            request.invoiceNumber = invoiceNumber;
            return this;
        }

        public Builder type(String type) {
            request.type = type;
            return this;
        }

        public Builder standard() {
            request.type = "standard";
            return this;
        }

        public Builder simplified() {
            request.type = "simplified";
            return this;
        }

        public Builder creditNote() {
            request.type = "credit_note";
            return this;
        }

        public Builder debitNote() {
            request.type = "debit_note";
            return this;
        }

        public Builder buyerName(String buyerName) {
            request.buyerName = buyerName;
            return this;
        }

        public Builder buyerVatNumber(String buyerVatNumber) {
            request.buyerVatNumber = buyerVatNumber;
            return this;
        }

        public Builder buyerAddress(BuyerAddress buyerAddress) {
            request.buyerAddress = buyerAddress;
            return this;
        }

        public Builder issueDate(String issueDate) {
            request.issueDate = issueDate;
            return this;
        }

        public Builder currency(String currency) {
            request.currency = currency;
            return this;
        }

        public Builder paymentMeansCode(String paymentMeansCode) {
            request.paymentMeansCode = paymentMeansCode;
            return this;
        }

        public Builder discountAmount(BigDecimal discountAmount) {
            request.discountAmount = discountAmount;
            return this;
        }

        public Builder notes(String notes) {
            request.notes = notes;
            return this;
        }

        public Builder billingReferenceId(String billingReferenceId) {
            request.billingReferenceId = billingReferenceId;
            return this;
        }

        public Builder lines(List<InvoiceLine> lines) {
            request.lines = lines;
            return this;
        }

        public Builder addLine(InvoiceLine line) {
            request.lines.add(line);
            return this;
        }

        public CreateInvoiceRequest build() {
            // Set defaults
            if (request.type == null) {
                request.type = "standard";
            }
            if (request.currency == null) {
                request.currency = "SAR";
            }
            if (request.paymentMeansCode == null) {
                request.paymentMeansCode = "10";
            }
            return request;
        }
    }
}
