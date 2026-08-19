package com.masaar.exceptions;

import java.util.List;

/**
 * Base exception for Masaar SDK errors.
 */
public class MasaarException extends Exception {

    private final Integer statusCode;
    private final List<String> errors;

    public MasaarException(String message) {
        this(message, null, null);
    }

    public MasaarException(String message, Integer statusCode) {
        this(message, statusCode, null);
    }

    public MasaarException(String message, Integer statusCode, List<String> errors) {
        super(message);
        this.statusCode = statusCode;
        this.errors = errors;
    }

    public MasaarException(String message, Throwable cause) {
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
