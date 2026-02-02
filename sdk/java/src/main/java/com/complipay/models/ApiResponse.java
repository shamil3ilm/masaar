package com.complipay.models;

import java.util.List;

/**
 * Standard API response wrapper.
 *
 * @param <T> The type of data in the response
 */
public class ApiResponse<T> {

    private boolean success;
    private T data;
    private String message;
    private List<String> errors;

    public ApiResponse() {
    }

    public ApiResponse(boolean success, T data, String message, List<String> errors) {
        this.success = success;
        this.data = data;
        this.message = message;
        this.errors = errors;
    }

    public boolean isSuccess() {
        return success;
    }

    public void setSuccess(boolean success) {
        this.success = success;
    }

    public T getData() {
        return data;
    }

    public void setData(T data) {
        this.data = data;
    }

    public String getMessage() {
        return message;
    }

    public void setMessage(String message) {
        this.message = message;
    }

    public List<String> getErrors() {
        return errors;
    }

    public void setErrors(List<String> errors) {
        this.errors = errors;
    }
}
