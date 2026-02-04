<?php

namespace Database\Seeders;

use App\Models\Tax\TaxCategory;
use App\Models\Tax\TaxRate;
use Illuminate\Database\Seeder;

class TaxCategorySeeder extends Seeder
{
    public function run(): void
    {
        // Standard Tax Categories (ZATCA/GCC compliant codes)
        $categories = [
            [
                'name' => 'Standard Rate',
                'code' => 'S',
                'description' => 'Standard VAT/GST applicable',
            ],
            [
                'name' => 'Zero Rate',
                'code' => 'Z',
                'description' => 'Zero rated supplies (exports, international transport)',
            ],
            [
                'name' => 'Exempt',
                'code' => 'E',
                'description' => 'Exempt supplies (financial services, real estate)',
            ],
            [
                'name' => 'Out of Scope',
                'code' => 'O',
                'description' => 'Out of VAT/GST scope',
            ],
        ];

        foreach ($categories as $category) {
            TaxCategory::firstOrCreate(
                ['code' => $category['code'], 'organization_id' => null],
                array_merge($category, ['is_active' => true])
            );
        }

        // VAT Rates by Country
        $vatRates = [
            // Saudi Arabia - 15%
            ['country_code' => 'SA', 'name' => 'Saudi VAT', 'rate' => 15.00, 'effective_from' => '2020-07-01'],

            // UAE - 5%
            ['country_code' => 'AE', 'name' => 'UAE VAT', 'rate' => 5.00, 'effective_from' => '2018-01-01'],

            // Bahrain - 10% (increased from 5% in 2022)
            ['country_code' => 'BH', 'name' => 'Bahrain VAT', 'rate' => 10.00, 'effective_from' => '2022-01-01'],

            // Oman - 5%
            ['country_code' => 'OM', 'name' => 'Oman VAT', 'rate' => 5.00, 'effective_from' => '2021-04-16'],

            // Qatar - No VAT yet (0%)
            ['country_code' => 'QA', 'name' => 'Qatar VAT', 'rate' => 0.00, 'effective_from' => '2024-01-01'],

            // Kuwait - No VAT yet (0%)
            ['country_code' => 'KW', 'name' => 'Kuwait VAT', 'rate' => 0.00, 'effective_from' => '2024-01-01'],
        ];

        $standardCategory = TaxCategory::where('code', 'S')->first();

        if ($standardCategory) {
            foreach ($vatRates as $rate) {
                TaxRate::firstOrCreate(
                    [
                        'tax_category_id' => $standardCategory->id,
                        'country_code' => $rate['country_code'],
                    ],
                    array_merge($rate, ['is_active' => true])
                );
            }
        }

        // Zero rates for all countries
        $zeroCategory = TaxCategory::where('code', 'Z')->first();

        if ($zeroCategory) {
            $countries = ['SA', 'AE', 'BH', 'OM', 'QA', 'KW'];
            foreach ($countries as $country) {
                TaxRate::firstOrCreate(
                    [
                        'tax_category_id' => $zeroCategory->id,
                        'country_code' => $country,
                    ],
                    [
                        'name' => 'Zero Rate',
                        'rate' => 0.00,
                        'effective_from' => '2020-01-01',
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
