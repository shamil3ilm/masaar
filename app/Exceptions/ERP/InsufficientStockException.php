<?php

declare(strict_types=1);

namespace App\Exceptions\ERP;

class InsufficientStockException extends ErpException
{
    protected string $errorCode = 'INSUFFICIENT_STOCK';
    protected int $httpStatus = 422;

    public static function forProduct(
        int $productId,
        string $productName,
        float $requested,
        float $available,
        ?int $warehouseId = null
    ): self {
        $message = "Insufficient stock for '{$productName}'. Requested: {$requested}, Available: {$available}";

        return new self($message, [
            'product_id' => $productId,
            'product_name' => $productName,
            'requested_quantity' => $requested,
            'available_quantity' => $available,
            'shortage' => $requested - $available,
            'warehouse_id' => $warehouseId,
        ]);
    }

    public static function forMultipleProducts(array $shortages): self
    {
        $count = count($shortages);
        $message = "Insufficient stock for {$count} product(s)";

        return new self($message, [
            'shortages' => $shortages,
        ]);
    }
}
