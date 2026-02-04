<?php

declare(strict_types=1);

namespace App\Exceptions\ERP;

class ValidationException extends ErpException
{
    protected string $errorCode = 'VALIDATION_ERROR';
    protected int $httpStatus = 422;

    public static function missingRequiredField(string $field, ?string $context = null): self
    {
        $message = "Required field '{$field}' is missing";
        if ($context) {
            $message .= " in {$context}";
        }

        return new self($message, [
            'field' => $field,
            'context' => $context,
        ]);
    }

    public static function invalidValue(string $field, $value, ?string $expectedFormat = null): self
    {
        $message = "Invalid value for field '{$field}'";
        if ($expectedFormat) {
            $message .= ". Expected: {$expectedFormat}";
        }

        return new self($message, [
            'field' => $field,
            'value' => $value,
            'expected_format' => $expectedFormat,
        ]);
    }

    public static function duplicateEntry(string $field, $value, ?string $context = null): self
    {
        $message = "Duplicate value '{$value}' for field '{$field}'";
        if ($context) {
            $message .= " in {$context}";
        }

        return new self($message, [
            'field' => $field,
            'value' => $value,
            'context' => $context,
        ]);
    }

    public static function referenceNotFound(string $field, $value, string $referencedEntity): self
    {
        $message = "Referenced {$referencedEntity} with {$field}='{$value}' not found";

        return new self($message, [
            'field' => $field,
            'value' => $value,
            'referenced_entity' => $referencedEntity,
        ]);
    }

    public static function businessRule(string $rule, ?array $details = null): self
    {
        return new self($rule, $details ?? []);
    }
}
