<?php

declare(strict_types=1);

namespace App\Exceptions\ERP;

class InsufficientBalanceException extends ErpException
{
    protected string $errorCode = 'INSUFFICIENT_BALANCE';
    protected int $httpStatus = 422;

    public static function forLeave(
        string $employeeName,
        string $leaveType,
        float $requested,
        float $available
    ): self {
        $message = "Insufficient leave balance for '{$employeeName}'. {$leaveType}: Requested {$requested} days, Available {$available} days";

        return new self($message, [
            'employee_name' => $employeeName,
            'leave_type' => $leaveType,
            'requested_days' => $requested,
            'available_days' => $available,
            'shortage' => $requested - $available,
        ]);
    }

    public static function forCredit(
        string $customerName,
        float $amount,
        float $creditLimit,
        float $currentBalance
    ): self {
        $available = $creditLimit - $currentBalance;
        $message = "Credit limit exceeded for '{$customerName}'. Amount: {$amount}, Available credit: {$available}";

        return new self($message, [
            'customer_name' => $customerName,
            'amount' => $amount,
            'credit_limit' => $creditLimit,
            'current_balance' => $currentBalance,
            'available_credit' => $available,
        ]);
    }

    public static function forPayment(
        string $documentType,
        string $documentNumber,
        float $amount,
        float $amountDue
    ): self {
        $message = "Payment amount ({$amount}) exceeds amount due ({$amountDue}) for {$documentType} {$documentNumber}";

        return new self($message, [
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'amount' => $amount,
            'amount_due' => $amountDue,
        ]);
    }
}
