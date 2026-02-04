<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Centralized error codes and messages for the ERP system.
 *
 * Format: MODULE_CATEGORY_SPECIFIC
 * Ranges:
 * - 1000-1999: Authentication & Authorization
 * - 2000-2999: Validation & Input
 * - 3000-3999: Business Logic
 * - 4000-4999: Resource/Entity
 * - 5000-5999: External Services
 * - 6000-6999: System/Internal
 */
class ErrorCodes
{
    // ==================== Authentication (1000-1099) ====================
    public const AUTH_INVALID_CREDENTIALS = [
        'code' => 'AUTH_1001',
        'message' => 'Invalid email or password.',
        'http_status' => 401,
    ];

    public const AUTH_TOKEN_EXPIRED = [
        'code' => 'AUTH_1002',
        'message' => 'Your session has expired. Please login again.',
        'http_status' => 401,
    ];

    public const AUTH_TOKEN_INVALID = [
        'code' => 'AUTH_1003',
        'message' => 'Invalid authentication token.',
        'http_status' => 401,
    ];

    public const AUTH_TOKEN_BLACKLISTED = [
        'code' => 'AUTH_1004',
        'message' => 'This session has been invalidated. Please login again.',
        'http_status' => 401,
    ];

    public const AUTH_ACCOUNT_DISABLED = [
        'code' => 'AUTH_1005',
        'message' => 'Your account has been disabled. Contact your administrator.',
        'http_status' => 403,
    ];

    public const AUTH_ACCOUNT_LOCKED = [
        'code' => 'AUTH_1006',
        'message' => 'Your account is temporarily locked due to too many failed attempts. Try again later.',
        'http_status' => 423,
    ];

    public const AUTH_2FA_REQUIRED = [
        'code' => 'AUTH_1007',
        'message' => 'Two-factor authentication is required.',
        'http_status' => 403,
    ];

    public const AUTH_2FA_INVALID = [
        'code' => 'AUTH_1008',
        'message' => 'Invalid two-factor authentication code.',
        'http_status' => 401,
    ];

    public const AUTH_PASSWORD_EXPIRED = [
        'code' => 'AUTH_1009',
        'message' => 'Your password has expired. Please reset your password.',
        'http_status' => 403,
    ];

    public const AUTH_EMAIL_NOT_VERIFIED = [
        'code' => 'AUTH_1010',
        'message' => 'Please verify your email address before logging in.',
        'http_status' => 403,
    ];

    // ==================== Authorization (1100-1199) ====================
    public const AUTHZ_PERMISSION_DENIED = [
        'code' => 'AUTHZ_1101',
        'message' => 'You do not have permission to perform this action.',
        'http_status' => 403,
    ];

    public const AUTHZ_ROLE_REQUIRED = [
        'code' => 'AUTHZ_1102',
        'message' => 'This action requires a specific role.',
        'http_status' => 403,
    ];

    public const AUTHZ_MODULE_DISABLED = [
        'code' => 'AUTHZ_1103',
        'message' => 'This module is not enabled for your organization.',
        'http_status' => 403,
    ];

    public const AUTHZ_FEATURE_DISABLED = [
        'code' => 'AUTHZ_1104',
        'message' => 'This feature is not enabled for your organization.',
        'http_status' => 403,
    ];

    public const AUTHZ_SUBSCRIPTION_REQUIRED = [
        'code' => 'AUTHZ_1105',
        'message' => 'This feature requires a higher subscription tier.',
        'http_status' => 403,
    ];

    public const AUTHZ_BRANCH_ACCESS_DENIED = [
        'code' => 'AUTHZ_1106',
        'message' => 'You do not have access to this branch.',
        'http_status' => 403,
    ];

    public const AUTHZ_ORGANIZATION_MISMATCH = [
        'code' => 'AUTHZ_1107',
        'message' => 'You cannot access resources from another organization.',
        'http_status' => 403,
    ];

    // ==================== Validation (2000-2099) ====================
    public const VALIDATION_FAILED = [
        'code' => 'VAL_2001',
        'message' => 'The provided data is invalid.',
        'http_status' => 422,
    ];

    public const VALIDATION_REQUIRED_FIELD = [
        'code' => 'VAL_2002',
        'message' => 'A required field is missing.',
        'http_status' => 422,
    ];

    public const VALIDATION_INVALID_FORMAT = [
        'code' => 'VAL_2003',
        'message' => 'The field format is invalid.',
        'http_status' => 422,
    ];

    public const VALIDATION_DUPLICATE_ENTRY = [
        'code' => 'VAL_2004',
        'message' => 'A record with this value already exists.',
        'http_status' => 422,
    ];

    public const VALIDATION_INVALID_DATE_RANGE = [
        'code' => 'VAL_2005',
        'message' => 'The date range is invalid.',
        'http_status' => 422,
    ];

    public const VALIDATION_FILE_TOO_LARGE = [
        'code' => 'VAL_2006',
        'message' => 'The uploaded file exceeds the maximum allowed size.',
        'http_status' => 422,
    ];

    public const VALIDATION_INVALID_FILE_TYPE = [
        'code' => 'VAL_2007',
        'message' => 'The file type is not allowed.',
        'http_status' => 422,
    ];

    // ==================== Business Logic (3000-3999) ====================

    // Accounting (3000-3099)
    public const ACCT_FISCAL_YEAR_CLOSED = [
        'code' => 'ACCT_3001',
        'message' => 'Cannot create entries in a closed fiscal year.',
        'http_status' => 400,
    ];

    public const ACCT_JOURNAL_UNBALANCED = [
        'code' => 'ACCT_3002',
        'message' => 'Journal entry debits and credits must be equal.',
        'http_status' => 400,
    ];

    public const ACCT_ACCOUNT_HAS_TRANSACTIONS = [
        'code' => 'ACCT_3003',
        'message' => 'Cannot delete account with existing transactions.',
        'http_status' => 400,
    ];

    public const ACCT_PERIOD_LOCKED = [
        'code' => 'ACCT_3004',
        'message' => 'This accounting period is locked.',
        'http_status' => 400,
    ];

    // Sales (3100-3199)
    public const SALES_INVOICE_ALREADY_PAID = [
        'code' => 'SALES_3101',
        'message' => 'This invoice has already been fully paid.',
        'http_status' => 400,
    ];

    public const SALES_INVOICE_VOIDED = [
        'code' => 'SALES_3102',
        'message' => 'Cannot perform this action on a voided invoice.',
        'http_status' => 400,
    ];

    public const SALES_INSUFFICIENT_CREDIT = [
        'code' => 'SALES_3103',
        'message' => 'Customer has exceeded their credit limit.',
        'http_status' => 400,
    ];

    public const SALES_PAYMENT_EXCEEDS_DUE = [
        'code' => 'SALES_3104',
        'message' => 'Payment amount exceeds the amount due.',
        'http_status' => 400,
    ];

    public const SALES_QUOTATION_EXPIRED = [
        'code' => 'SALES_3105',
        'message' => 'This quotation has expired.',
        'http_status' => 400,
    ];

    public const SALES_ORDER_ALREADY_INVOICED = [
        'code' => 'SALES_3106',
        'message' => 'This order has already been fully invoiced.',
        'http_status' => 400,
    ];

    // Inventory (3200-3299)
    public const INV_INSUFFICIENT_STOCK = [
        'code' => 'INV_3201',
        'message' => 'Insufficient stock available.',
        'http_status' => 400,
    ];

    public const INV_NEGATIVE_STOCK = [
        'code' => 'INV_3202',
        'message' => 'This operation would result in negative stock.',
        'http_status' => 400,
    ];

    public const INV_PRODUCT_INACTIVE = [
        'code' => 'INV_3203',
        'message' => 'This product is inactive and cannot be used.',
        'http_status' => 400,
    ];

    public const INV_WAREHOUSE_TRANSFER_SAME = [
        'code' => 'INV_3204',
        'message' => 'Source and destination warehouses cannot be the same.',
        'http_status' => 400,
    ];

    // HR (3300-3399)
    public const HR_LEAVE_INSUFFICIENT_BALANCE = [
        'code' => 'HR_3301',
        'message' => 'Insufficient leave balance.',
        'http_status' => 400,
    ];

    public const HR_LEAVE_OVERLAPPING = [
        'code' => 'HR_3302',
        'message' => 'Leave request overlaps with an existing approved leave.',
        'http_status' => 400,
    ];

    public const HR_PAYROLL_ALREADY_PROCESSED = [
        'code' => 'HR_3303',
        'message' => 'Payroll for this period has already been processed.',
        'http_status' => 400,
    ];

    public const HR_EMPLOYEE_TERMINATED = [
        'code' => 'HR_3304',
        'message' => 'Cannot perform this action on a terminated employee.',
        'http_status' => 400,
    ];

    public const HR_LEAVE_TIER_EXCEEDED = [
        'code' => 'HR_3305',
        'message' => 'Leave request exceeds your tier allowance.',
        'http_status' => 400,
    ];

    // Approval (3400-3499)
    public const APPROVAL_ALREADY_PROCESSED = [
        'code' => 'APPR_3401',
        'message' => 'This request has already been processed.',
        'http_status' => 400,
    ];

    public const APPROVAL_NOT_AUTHORIZED = [
        'code' => 'APPR_3402',
        'message' => 'You are not authorized to approve this request.',
        'http_status' => 403,
    ];

    public const APPROVAL_COMMENT_REQUIRED = [
        'code' => 'APPR_3403',
        'message' => 'A comment is required for this action.',
        'http_status' => 400,
    ];

    // Wallet & Payments (3500-3599)
    public const WALLET_INSUFFICIENT_BALANCE = [
        'code' => 'WALLET_3501',
        'message' => 'Insufficient wallet balance.',
        'http_status' => 400,
    ];

    public const WALLET_NEGATIVE_NOT_ALLOWED = [
        'code' => 'WALLET_3502',
        'message' => 'Negative wallet balance is not allowed.',
        'http_status' => 400,
    ];

    public const PAYMENT_ALREADY_REFUNDED = [
        'code' => 'PAY_3503',
        'message' => 'This payment has already been refunded.',
        'http_status' => 400,
    ];

    public const ADVANCE_FULLY_APPLIED = [
        'code' => 'PAY_3504',
        'message' => 'This advance payment has already been fully applied.',
        'http_status' => 400,
    ];

    // ==================== Resource/Entity (4000-4999) ====================
    public const RESOURCE_NOT_FOUND = [
        'code' => 'RES_4001',
        'message' => 'The requested resource was not found.',
        'http_status' => 404,
    ];

    public const RESOURCE_ALREADY_EXISTS = [
        'code' => 'RES_4002',
        'message' => 'A resource with this identifier already exists.',
        'http_status' => 409,
    ];

    public const RESOURCE_DELETED = [
        'code' => 'RES_4003',
        'message' => 'This resource has been deleted.',
        'http_status' => 410,
    ];

    public const RESOURCE_LOCKED = [
        'code' => 'RES_4004',
        'message' => 'This resource is currently locked.',
        'http_status' => 423,
    ];

    public const RESOURCE_IN_USE = [
        'code' => 'RES_4005',
        'message' => 'Cannot delete this resource as it is being used.',
        'http_status' => 400,
    ];

    public const RESOURCE_ARCHIVED = [
        'code' => 'RES_4006',
        'message' => 'This resource has been archived.',
        'http_status' => 400,
    ];

    // ==================== External Services (5000-5999) ====================
    public const EXT_SERVICE_UNAVAILABLE = [
        'code' => 'EXT_5001',
        'message' => 'An external service is currently unavailable.',
        'http_status' => 503,
    ];

    public const EXT_COMPLIANCE_FAILED = [
        'code' => 'EXT_5002',
        'message' => 'Compliance submission failed.',
        'http_status' => 400,
    ];

    public const EXT_PAYMENT_GATEWAY_ERROR = [
        'code' => 'EXT_5003',
        'message' => 'Payment gateway error occurred.',
        'http_status' => 400,
    ];

    public const EXT_EMAIL_SEND_FAILED = [
        'code' => 'EXT_5004',
        'message' => 'Failed to send email notification.',
        'http_status' => 500,
    ];

    public const EXT_ECOMMERCE_SYNC_FAILED = [
        'code' => 'EXT_5005',
        'message' => 'E-commerce synchronization failed.',
        'http_status' => 400,
    ];

    // ==================== System/Internal (6000-6999) ====================
    public const SYS_INTERNAL_ERROR = [
        'code' => 'SYS_6001',
        'message' => 'An unexpected error occurred. Please try again later.',
        'http_status' => 500,
    ];

    public const SYS_DATABASE_ERROR = [
        'code' => 'SYS_6002',
        'message' => 'A database error occurred.',
        'http_status' => 500,
    ];

    public const SYS_RATE_LIMIT_EXCEEDED = [
        'code' => 'SYS_6003',
        'message' => 'Too many requests. Please slow down.',
        'http_status' => 429,
    ];

    public const SYS_MAINTENANCE_MODE = [
        'code' => 'SYS_6004',
        'message' => 'System is under maintenance. Please try again later.',
        'http_status' => 503,
    ];

    public const SYS_QUOTA_EXCEEDED = [
        'code' => 'SYS_6005',
        'message' => 'You have exceeded your usage quota.',
        'http_status' => 429,
    ];

    public const SYS_STORAGE_LIMIT = [
        'code' => 'SYS_6006',
        'message' => 'Storage limit exceeded for your organization.',
        'http_status' => 400,
    ];

    /**
     * Get error details by constant name.
     */
    public static function get(string $name): array
    {
        $constant = constant("self::{$name}");
        return $constant;
    }

    /**
     * Format error response.
     */
    public static function format(array $error, ?array $details = null, ?string $customMessage = null): array
    {
        return [
            'error' => [
                'code' => $error['code'],
                'message' => $customMessage ?? $error['message'],
                'details' => $details,
            ],
        ];
    }
}
