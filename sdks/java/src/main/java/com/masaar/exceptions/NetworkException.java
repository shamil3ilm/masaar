package com.masaar.exceptions;

/**
 * Thrown when a network error occurs.
 */
public class NetworkException extends MasaarException {

    public NetworkException(String message) {
        super(message);
    }

    public NetworkException(String message, Throwable cause) {
        super(message, cause);
    }
}
