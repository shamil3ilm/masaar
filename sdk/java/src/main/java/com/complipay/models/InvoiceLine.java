package com.complipay.models;

import com.google.gson.annotations.SerializedName;
import java.math.BigDecimal;

/**
 * Invoice line item.
 */
public class InvoiceLine {

    private String description;
    private BigDecimal quantity;

    @SerializedName("unit_price")
    private BigDecimal unitPrice;

    @SerializedName("tax_rate")
    private BigDecimal taxRate;

    @SerializedName("tax_category")
    private String taxCategory;

    @SerializedName("unit_code")
    private String unitCode;

    @SerializedName("tax_exemption_code")
    private String taxExemptionCode;

    @SerializedName("tax_exemption_reason")
    private String taxExemptionReason;

    @SerializedName("item_classification_code")
    private String itemClassificationCode;

    private BigDecimal discount;

    @SerializedName("line_total")
    private BigDecimal lineTotal;

    @SerializedName("tax_amount")
    private BigDecimal taxAmount;

    // Builder pattern for easy construction
    public static Builder builder() {
        return new Builder();
    }

    // Getters and Setters

    public String getDescription() {
        return description;
    }

    public void setDescription(String description) {
        this.description = description;
    }

    public BigDecimal getQuantity() {
        return quantity;
    }

    public void setQuantity(BigDecimal quantity) {
        this.quantity = quantity;
    }

    public BigDecimal getUnitPrice() {
        return unitPrice;
    }

    public void setUnitPrice(BigDecimal unitPrice) {
        this.unitPrice = unitPrice;
    }

    public BigDecimal getTaxRate() {
        return taxRate;
    }

    public void setTaxRate(BigDecimal taxRate) {
        this.taxRate = taxRate;
    }

    public String getTaxCategory() {
        return taxCategory;
    }

    public void setTaxCategory(String taxCategory) {
        this.taxCategory = taxCategory;
    }

    public String getUnitCode() {
        return unitCode;
    }

    public void setUnitCode(String unitCode) {
        this.unitCode = unitCode;
    }

    public String getTaxExemptionCode() {
        return taxExemptionCode;
    }

    public void setTaxExemptionCode(String taxExemptionCode) {
        this.taxExemptionCode = taxExemptionCode;
    }

    public String getTaxExemptionReason() {
        return taxExemptionReason;
    }

    public void setTaxExemptionReason(String taxExemptionReason) {
        this.taxExemptionReason = taxExemptionReason;
    }

    public String getItemClassificationCode() {
        return itemClassificationCode;
    }

    public void setItemClassificationCode(String itemClassificationCode) {
        this.itemClassificationCode = itemClassificationCode;
    }

    public BigDecimal getDiscount() {
        return discount;
    }

    public void setDiscount(BigDecimal discount) {
        this.discount = discount;
    }

    public BigDecimal getLineTotal() {
        return lineTotal;
    }

    public void setLineTotal(BigDecimal lineTotal) {
        this.lineTotal = lineTotal;
    }

    public BigDecimal getTaxAmount() {
        return taxAmount;
    }

    public void setTaxAmount(BigDecimal taxAmount) {
        this.taxAmount = taxAmount;
    }

    /**
     * Builder for InvoiceLine.
     */
    public static class Builder {
        private final InvoiceLine line = new InvoiceLine();

        public Builder description(String description) {
            line.description = description;
            return this;
        }

        public Builder quantity(double quantity) {
            line.quantity = BigDecimal.valueOf(quantity);
            return this;
        }

        public Builder quantity(BigDecimal quantity) {
            line.quantity = quantity;
            return this;
        }

        public Builder unitPrice(double unitPrice) {
            line.unitPrice = BigDecimal.valueOf(unitPrice);
            return this;
        }

        public Builder unitPrice(BigDecimal unitPrice) {
            line.unitPrice = unitPrice;
            return this;
        }

        public Builder taxRate(double taxRate) {
            line.taxRate = BigDecimal.valueOf(taxRate);
            return this;
        }

        public Builder taxCategory(String taxCategory) {
            line.taxCategory = taxCategory;
            return this;
        }

        public Builder unitCode(String unitCode) {
            line.unitCode = unitCode;
            return this;
        }

        public Builder taxExemptionCode(String code) {
            line.taxExemptionCode = code;
            return this;
        }

        public Builder taxExemptionReason(String reason) {
            line.taxExemptionReason = reason;
            return this;
        }

        public Builder itemClassificationCode(String code) {
            line.itemClassificationCode = code;
            return this;
        }

        public Builder discount(double discount) {
            line.discount = BigDecimal.valueOf(discount);
            return this;
        }

        public InvoiceLine build() {
            // Set defaults
            if (line.taxRate == null) {
                line.taxRate = BigDecimal.valueOf(15.0);
            }
            if (line.taxCategory == null) {
                line.taxCategory = "S";
            }
            if (line.unitCode == null) {
                line.unitCode = "PCE";
            }
            return line;
        }
    }
}
