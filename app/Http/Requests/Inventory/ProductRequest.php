<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\Inventory\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        $rules = [
            'sku' => [
                'required',
                'string',
                'max:50',
                Rule::unique('products', 'sku')
                    ->ignore($productId)
                    ->where('organization_id', auth()->user()->organization_id),
            ],
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'type' => 'required|in:goods,service',

            'category_id' => 'nullable|integer|exists:categories,id',
            'unit_id' => 'nullable|integer|exists:units_of_measure,id',
            'tax_category_id' => 'nullable|integer|exists:tax_categories,id',

            'purchase_price' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'costing_method' => 'nullable|in:fifo,lifo,weighted_average',

            'barcode' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('products', 'barcode')
                    ->ignore($productId)
                    ->whereNotNull('barcode'),
            ],
            'hsn_code' => 'nullable|string|max:20',

            'income_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'expense_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'inventory_account_id' => 'nullable|integer|exists:chart_of_accounts,id',

            'track_inventory' => 'boolean',
            'is_active' => 'boolean',

            'reorder_level' => 'nullable|numeric|min:0',
            'reorder_quantity' => 'nullable|numeric|min:0',

            'weight' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|array',
            'dimensions.length' => 'nullable|numeric|min:0',
            'dimensions.width' => 'nullable|numeric|min:0',
            'dimensions.height' => 'nullable|numeric|min:0',
            'image_url' => 'nullable|string|max:255',

            // Variants
            'variants' => 'nullable|array',
            'variants.*.sku' => 'required|string|max:50',
            'variants.*.name' => 'nullable|string|max:200',
            'variants.*.attribute_values' => 'nullable|array',
            'variants.*.purchase_price' => 'nullable|numeric|min:0',
            'variants.*.selling_price' => 'nullable|numeric|min:0',
            'variants.*.barcode' => 'nullable|string|max:50',
            'variants.*.weight' => 'nullable|numeric|min:0',
            'variants.*.is_active' => 'boolean',
        ];

        // For update requests, make certain fields optional
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['sku'] = [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('products', 'sku')
                    ->ignore($productId)
                    ->where('organization_id', auth()->user()->organization_id),
            ];
            $rules['name'] = 'sometimes|required|string|max:200';
            $rules['type'] = 'sometimes|required|in:goods,service';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'This SKU is already in use.',
            'barcode.unique' => 'This barcode is already assigned to another product.',
            'variants.*.sku.required' => 'Each variant must have a SKU.',
        ];
    }
}
