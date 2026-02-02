package com.complipay.exceptions;

/**
 * Thrown when a network error occurs.
 */
public class NetworkException extends CompliPayException {

    public NetworkException(String message) {
        super(message);
    }

    public NetworkException(String message, Throwable cause) {
        super(message, cause);
    }
}
