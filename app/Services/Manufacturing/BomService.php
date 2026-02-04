<?php

declare(strict_types=1);

namespace App\Services\Manufacturing;

use App\Models\Manufacturing\BomLine;
use App\Models\Manufacturing\BomOperation;
use App\Models\Manufacturing\BomTemplate;
use App\Services\Core\NumberGeneratorService;
use Illuminate\Support\Facades\DB;

class BomService
{
    public function __construct(
        private NumberGeneratorService $numberGenerator
    ) {}

    /**
     * Create a new BOM template.
     */
    public function create(array $data, array $lines = [], array $operations = []): BomTemplate
    {
        return DB::transaction(function () use ($data, $lines, $operations) {
            if (empty($data['bom_number'])) {
                $data['bom_number'] = $this->numberGenerator->generate('BOM');
            }

            $data['status'] = $data['status'] ?? BomTemplate::STATUS_DRAFT;
            $data['created_by'] = auth()->id();

            $bom = BomTemplate::create($data);

            // Create BOM lines
            foreach ($lines as $index => $lineData) {
                $this->createLine($bom, $lineData, $index);
            }

            // Create BOM operations
            foreach ($operations as $index => $operationData) {
                $this->createOperation($bom, $operationData, $index);
            }

            return $bom->fresh(['lines.product', 'operations']);
        });
    }

    /**
     * Update a BOM template.
     */
    public function update(BomTemplate $bom, array $data, ?array $lines = null, ?array $operations = null): BomTemplate
    {
        if (!$bom->canBeEdited()) {
            throw new \InvalidArgumentException('Only draft BOMs can be edited.');
        }

        return DB::transaction(function () use ($bom, $data, $lines, $operations) {
            $bom->update($data);

            // Update lines if provided
            if ($lines !== null) {
                $bom->lines()->delete();
                foreach ($lines as $index => $lineData) {
                    $this->createLine($bom, $lineData, $index);
                }
            }

            // Update operations if provided
            if ($operations !== null) {
                $bom->operations()->delete();
                foreach ($operations as $index => $operationData) {
                    $this->createOperation($bom, $operationData, $index);
                }
            }

            return $bom->fresh(['lines.product', 'operations']);
        });
    }

    /**
     * Create a BOM line.
     */
    protected function createLine(BomTemplate $bom, array $data, int $index): BomLine
    {
        return BomLine::create([
            'bom_template_id' => $bom->id,
            'product_id' => $data['product_id'],
            'variant_id' => $data['variant_id'] ?? null,
            'description' => $data['description'] ?? null,
            'quantity' => $data['quantity'],
            'unit_id' => $data['unit_id'] ?? null,
            'unit_cost' => $data['unit_cost'] ?? null,
            'wastage_percentage' => $data['wastage_percentage'] ?? 0,
            'is_critical' => $data['is_critical'] ?? false,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'line_order' => $data['line_order'] ?? $index,
        ]);
    }

    /**
     * Create a BOM operation.
     */
    protected function createOperation(BomTemplate $bom, array $data, int $index): BomOperation
    {
        return BomOperation::create([
            'bom_template_id' => $bom->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'sequence' => $data['sequence'] ?? $index,
            'estimated_minutes' => $data['estimated_minutes'] ?? 0,
            'labor_cost_per_hour' => $data['labor_cost_per_hour'] ?? null,
            'workstation' => $data['workstation'] ?? null,
            'required_skills' => $data['required_skills'] ?? null,
            'is_subcontracted' => $data['is_subcontracted'] ?? false,
        ]);
    }

    /**
     * Activate a BOM template.
     */
    public function activate(BomTemplate $bom): BomTemplate
    {
        if ($bom->lines()->count() === 0) {
            throw new \InvalidArgumentException('BOM must have at least one line to be activated.');
        }

        $bom->update(['status' => BomTemplate::STATUS_ACTIVE]);

        return $bom->fresh();
    }

    /**
     * Deactivate a BOM template.
     */
    public function deactivate(BomTemplate $bom): BomTemplate
    {
        $bom->update(['status' => BomTemplate::STATUS_INACTIVE]);

        return $bom->fresh();
    }

    /**
     * Duplicate a BOM template.
     */
    public function duplicate(BomTemplate $bom, ?array $overrides = []): BomTemplate
    {
        return DB::transaction(function () use ($bom, $overrides) {
            $newBom = BomTemplate::create([
                'organization_id' => $bom->organization_id,
                'bom_number' => $this->numberGenerator->generate('BOM'),
                'name' => $overrides['name'] ?? "{$bom->name} (Copy)",
                'description' => $overrides['description'] ?? $bom->description,
                'product_id' => $overrides['product_id'] ?? $bom->product_id,
                'variant_id' => $overrides['variant_id'] ?? $bom->variant_id,
                'output_quantity' => $overrides['output_quantity'] ?? $bom->output_quantity,
                'output_unit_id' => $bom->output_unit_id,
                'default_warehouse_id' => $bom->default_warehouse_id,
                'estimated_hours' => $bom->estimated_hours,
                'estimated_labor_cost' => $bom->estimated_labor_cost,
                'overhead_cost' => $bom->overhead_cost,
                'status' => BomTemplate::STATUS_DRAFT,
                'version' => 1,
                'notes' => $bom->notes,
                'created_by' => auth()->id(),
            ]);

            // Duplicate lines
            foreach ($bom->lines as $line) {
                BomLine::create([
                    'bom_template_id' => $newBom->id,
                    'product_id' => $line->product_id,
                    'variant_id' => $line->variant_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_id' => $line->unit_id,
                    'unit_cost' => $line->unit_cost,
                    'wastage_percentage' => $line->wastage_percentage,
                    'is_critical' => $line->is_critical,
                    'warehouse_id' => $line->warehouse_id,
                    'line_order' => $line->line_order,
                ]);
            }

            // Duplicate operations
            foreach ($bom->operations as $operation) {
                BomOperation::create([
                    'bom_template_id' => $newBom->id,
                    'name' => $operation->name,
                    'description' => $operation->description,
                    'instructions' => $operation->instructions,
                    'sequence' => $operation->sequence,
                    'estimated_minutes' => $operation->estimated_minutes,
                    'labor_cost_per_hour' => $operation->labor_cost_per_hour,
                    'workstation' => $operation->workstation,
                    'required_skills' => $operation->required_skills,
                    'is_subcontracted' => $operation->is_subcontracted,
                ]);
            }

            return $newBom->fresh(['lines.product', 'operations']);
        });
    }

    /**
     * Create a new version of a BOM template.
     */
    public function createNewVersion(BomTemplate $bom): BomTemplate
    {
        $newVersion = $bom->version + 1;

        // Deactivate the current version
        $bom->update(['status' => BomTemplate::STATUS_INACTIVE]);

        return $this->duplicate($bom, [
            'name' => $bom->name,
        ])->tap(function ($newBom) use ($newVersion) {
            $newBom->update(['version' => $newVersion]);
        });
    }

    /**
     * Get cost breakdown for a BOM.
     */
    public function getCostBreakdown(BomTemplate $bom, float $quantity = 1): array
    {
        $costs = $bom->calculateTotalCost($quantity);

        $materialBreakdown = [];
        $multiplier = $quantity / (float) $bom->output_quantity;

        foreach ($bom->lines as $line) {
            $lineQuantity = (float) $line->quantity * $multiplier;
            $wastageMultiplier = 1 + ((float) $line->wastage_percentage / 100);
            $adjustedQuantity = $lineQuantity * $wastageMultiplier;
            $lineCost = (float) bcmul((string) $adjustedQuantity, (string) ($line->unit_cost ?? 0), 4);

            $materialBreakdown[] = [
                'product_id' => $line->product_id,
                'product_name' => $line->product->name,
                'quantity' => round($lineQuantity, 4),
                'adjusted_quantity' => round($adjustedQuantity, 4),
                'wastage_percentage' => $line->wastage_percentage,
                'unit_cost' => $line->unit_cost,
                'total_cost' => $lineCost,
                'is_critical' => $line->is_critical,
            ];
        }

        $laborBreakdown = [];
        foreach ($bom->operations as $operation) {
            $hours = ($operation->estimated_minutes / 60) * $multiplier;
            $operationCost = (float) bcmul((string) $hours, (string) ($operation->labor_cost_per_hour ?? 0), 4);

            $laborBreakdown[] = [
                'operation_name' => $operation->name,
                'estimated_hours' => round($hours, 2),
                'labor_cost_per_hour' => $operation->labor_cost_per_hour,
                'total_cost' => $operationCost,
                'is_subcontracted' => $operation->is_subcontracted,
            ];
        }

        return [
            'quantity' => $quantity,
            'material_cost' => $costs['material_cost'],
            'labor_cost' => $costs['labor_cost'],
            'overhead_cost' => $costs['overhead_cost'],
            'total_cost' => $costs['total_cost'],
            'unit_cost' => $costs['unit_cost'],
            'material_breakdown' => $materialBreakdown,
            'labor_breakdown' => $laborBreakdown,
        ];
    }

    /**
     * Check material availability for production.
     */
    public function checkAvailability(BomTemplate $bom, float $quantity, ?int $warehouseId = null): array
    {
        $availability = $bom->checkMaterialAvailability($quantity, $warehouseId);

        $canProduce = true;
        $criticalShortage = false;

        foreach ($availability as $material) {
            if (!$material['is_sufficient']) {
                $canProduce = false;
                if ($material['is_critical']) {
                    $criticalShortage = true;
                }
            }
        }

        return [
            'can_produce' => $canProduce,
            'critical_shortage' => $criticalShortage,
            'materials' => $availability,
        ];
    }

    /**
     * Get BOMs for a product.
     */
    public function getForProduct(int $productId, bool $activeOnly = true): \Illuminate\Support\Collection
    {
        $query = BomTemplate::forProduct($productId);

        if ($activeOnly) {
            $query->active()->effective();
        }

        return $query->with(['lines.product', 'operations'])->get();
    }

    /**
     * Get default BOM for a product.
     */
    public function getDefaultForProduct(int $productId): ?BomTemplate
    {
        return BomTemplate::forProduct($productId)
            ->active()
            ->effective()
            ->orderBy('version', 'desc')
            ->first();
    }
}
