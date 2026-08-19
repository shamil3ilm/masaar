package com.masaar.exceptions;

/**
 * Thrown when rate limit is exceeded.
 */
public class RateLimitException extends MasaarException {

    public RateLimitException(String message) {
        super(message, 429);
    }
}
