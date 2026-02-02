package com.complipay.exceptions;

/**
 * Thrown when rate limit is exceeded.
 */
public class RateLimitException extends CompliPayException {

    public RateLimitException(String message) {
        super(message, 429);
    }
}
