package com.complipay.exceptions;

/**
 * Thrown when authentication fails (invalid API key/secret or JWT token).
 */
public class AuthenticationException extends CompliPayException {

    public AuthenticationException(String message) {
        super(message, 401);
    }
}
