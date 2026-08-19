package com.masaar.exceptions;

/**
 * Thrown when authentication fails (invalid API key/secret or JWT token).
 */
public class AuthenticationException extends MasaarException {

    public AuthenticationException(String message) {
        super(message, 401);
    }
}
