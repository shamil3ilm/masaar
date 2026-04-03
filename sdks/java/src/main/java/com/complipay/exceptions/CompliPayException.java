package com.complipay.exceptions;

import java.util.List;

/**
 * Base exception for CompliPay SDK errors.
 */
public class CompliPayException extends Exception {

    private final Integer statusCode;
    private final List<String> errors;

    public CompliPayException(String message) {
        this(message, null, null);
    }

    public CompliPayException(String message, Integer statusCode) {
        this(message, statusCode, null);
    }

    public CompliPayException(String message, Integer statusCode, List<String> errors) {
        super(message);
        this.statusCode = statusCode;
        this.errors = errors;
    }

    public CompliPayException(String message, Throwable cause) {
        super(message, cause);
        this.statusCode = null;
        this.errors = null;
    }

    public Integer getStatusCode() {
        return statusCode;
    }

    public List<String> getErrors() {
        return errors;
    }
}
