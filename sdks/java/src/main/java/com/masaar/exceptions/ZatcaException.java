package com.masaar.exceptions;

import java.util.List;

/**
 * Thrown when ZATCA submission or validation fails.
 */
public class ZatcaException extends MasaarException {

    public ZatcaException(String message) {
        super(message);
    }

    public ZatcaException(String message, List<String> errors) {
        super(message, null, errors);
    }
}
