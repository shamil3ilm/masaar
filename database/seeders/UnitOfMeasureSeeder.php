<?php

namespace Database\Seeders;

use App\Models\Inventory\UnitOfMeasure;
use Illuminate\Database\Seeder;

class UnitOfMeasureSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            // Quantity
            ['name' => 'Each', 'symbol' => 'ea', 'base_unit_id' => null, 'conversion_factor' => 1],
            ['name' => 'Piece', 'symbol' => 'pc', 'base_unit_id' => null, 'conversion_factor' => 1],
            ['name' => 'Pair', 'symbol' => 'pr', 'base_unit_id' => null, 'conversion_factor' => 2],
            ['name' => 'Dozen', 'symbol' => 'doz', 'base_unit_id' => null, 'conversion_factor' => 12],
            ['name' => 'Gross', 'symbol' => 'gro', 'base_unit_id' => null, 'conversion_factor' => 144],
            ['name' => 'Set', 'symbol' => 'set', 'base_unit_id' => null, 'conversion_factor' => 1],
            ['name' => 'Pack', 'symbol' => 'pk', 'base_unit_id' => null, 'conversion_factor' => 1],
            ['name' => 'Box', 'symbol' => 'box', 'base_unit_id' => null, 'conversion_factor' => 1],
            ['name' => 'Carton', 'symbol' => 'ctn', 'base_unit_id' => null, 'conversion_factor' => 1],
            ['name' => 'Case', 'symbol' => 'cs', 'base_unit_id' => null, 'conversion_factor' => 1],
            ['name' => 'Pallet', 'symbol' => 'plt', 'base_unit_id' => null, 'conversion_factor' => 1],

            // Weight (Metric)
            ['name' => 'Milligram', 'symbol' => 'mg', 'base_unit_id' => null, 'conversion_factor' => 0.000001],
            ['name' => 'Gram', 'symbol' => 'g', 'base_unit_id' => null, 'conversion_factor' => 0.001],
            ['name' => 'Kilogram', 'symbol' => 'kg', 'base_unit_id' => null, 'conversion_factor' => 1],
            ['name' => 'Metric Ton', 'symbol' => 'mt', 'base_unit_id' => null, 'conversion_factor' => 1000],
            ['name' => 'Quintal', 'symbol' => 'q', 'base_unit_id' => null, 'conversion_factor' => 100],

            // Weight (Imperial)
            ['name' => 'Ounce', 'symbol' => 'oz', 'base_unit_id' => null, 'conversion_factor' => 0.0283495],
            ['name' => 'Pound', 'symbol' => 'lb', 'base_unit_id' => null, 'conversion_factor' => 0.453592],

            // Volume (Metric)
            ['name' => 'Milliliter', 'symbol' => 'ml', 'base_unit_id' => null, 'conversion_factor' => 0.001],
            ['name' => 'Centiliter', 'symbol' => 'cl', 'base_unit_id' => null, 'conversion_factor' => 0.01],
            ['name' => 'Liter', 'symbol' => 'l', 'base_unit_id' => null, 'conversion_factor' => 1],
            ['name' => 'Cubic Meter', 'symbol' => 'm³', 'base_unit_id' => null, 'conversion_factor' => 1000],

            // Volume (Imperial)
            ['name' => 'Fluid Ounce', 'symbol' => 'fl oz', 'base_unit_id' => null, 'conversion_factor' => 0.0295735],
            ['name' => 'Gallon (US)', 'symbol' => 'gal', 'base_unit_id' => null, 'conversion_factor' => 3.78541],

            // Length (Metric)
            ['name' => 'Millimeter', 'symbol' => 'mm', 'base_unit_id' => null, 'conversion_factor' => 0.001],
            ['name' => 'Centimeter', 'symbol' => 'cm', 'base_unit_id' => null, 'conversion_factor' => 0.01],
            ['name' => 'Meter', 'symbol' => 'm', 'base_unit_id' => null, 'conversion_factor' => 1],
            ['name' => 'Kilometer', 'symbol' => 'km', 'base_unit_id' => null, 'conversion_factor' => 1000],

            // Length (Imperial)
            ['name' => 'Inch', 'symbol' => 'in', 'base_unit_id' => null, 'conversion_factor' => 0.0254],
            ['name' => 'Foot', 'symbol' => 'ft', 'base_unit_id' => null, 'conversion_factor' => 0.3048],
            ['name' => 'Yard', 'symbol' => 'yd', 'base_unit_id' => null, 'conversion_factor' => 0.9144],

            // Area
            ['name' => 'Square Meter', 'symbol' => 'm²', 'base_unit_id' => null, 'conversion_factor' => 1],
            ['name' => 'Square Foot', 'symbol' => 'sq ft', 'base_unit_id' => null, 'conversion_factor' => 0.092903],

            // Time
            ['name' => 'Hour', 'symbol' => 'hr', 'base_unit_id' => null, 'conversion_factor' => 1],
            ['name' => 'Day', 'symbol' => 'day', 'base_unit_id' => null, 'conversion_factor' => 24],
            ['name' => 'Week', 'symbol' => 'wk', 'base_unit_id' => null, 'conversion_factor' => 168],
            ['name' => 'Month', 'symbol' => 'mo', 'base_unit_id' => null, 'conversion_factor' => 720],
        ];

        foreach ($units as $unit) {
            UnitOfMeasure::firstOrCreate(
                ['symbol' => $unit['symbol'], 'organization_id' => null],
                $unit
            );
        }
    }
}
