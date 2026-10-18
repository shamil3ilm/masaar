<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Exceptions\RecordInUseException;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Product;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Services\CatalogueRemoval;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Archiving and removing the organization's saved customers and products
 * through the API, by the portal's rule (CatalogueRemoval): one an invoice
 * used can only be archived, so removing it answers 409 RECORD_IN_USE with
 * the archive route to call instead.
 */
class CatalogueController extends Controller
{
    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly CatalogueRemoval $removal,
        private readonly TenantResolver $tenant,
    ) {}

    /**
     * Remove a customer no invoice has used.
     *
     * DELETE /api/customers/{id}
     */
    public function destroyCustomer(string $customer): JsonResponse
    {
        return $this->remove($this->customer($customer), '/api/customers/');
    }

    /**
     * Archive a customer: out of the pickers, kept for the invoices that used it.
     *
     * POST /api/customers/{id}/archive
     */
    public function archiveCustomer(string $customer): JsonResponse
    {
        return $this->archive($this->customer($customer));
    }

    /**
     * Remove a product no invoice has used.
     *
     * DELETE /api/products/{id}
     */
    public function destroyProduct(string $product): JsonResponse
    {
        return $this->remove($this->product($product), '/api/products/');
    }

    /**
     * Archive a product: out of the pickers, kept for the invoices that used it.
     *
     * POST /api/products/{id}/archive
     */
    public function archiveProduct(string $product): JsonResponse
    {
        return $this->archive($this->product($product));
    }

    private function remove(Customer|Product $record, string $prefix): JsonResponse
    {
        try {
            $this->removal->remove($record);
        } catch (RecordInUseException $e) {
            return ApiResponse::error($e->getMessage(), 409, ['archive' => url($prefix.$record->id.'/archive')], 'RECORD_IN_USE');
        }

        return ApiResponse::success(null, 'Removed');
    }

    private function archive(Customer|Product $record): JsonResponse
    {
        $this->catalogue->archive($record, true);

        return ApiResponse::success(['id' => (string) $record->id, 'archived_at' => $record->archived_at?->toIso8601String()], 'Archived');
    }

    private function customer(string $id): Customer
    {
        return $this->catalogue->customer((string) $this->tenant->getOrganizationId(), $id) ?? abort(404);
    }

    private function product(string $id): Product
    {
        return $this->catalogue->product((string) $this->tenant->getOrganizationId(), $id) ?? abort(404);
    }
}
