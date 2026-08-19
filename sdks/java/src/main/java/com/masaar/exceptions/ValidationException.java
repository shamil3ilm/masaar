package com.masaar.exceptions;

import java.util.List;

/**
 * Thrown when request validation fails.
 */
public class ValidationException extends MasaarException {

    public ValidationException(String message, List<String> errors) {
        super(message, 422, errors);
    }
}
