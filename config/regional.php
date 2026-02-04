<?php

/**
 * Regional Compliance Configuration
 *
 * This file contains tax rates, compliance requirements, and business rules
 * for different regions supported by the ERP system.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | GCC Countries - VAT Compliance
    |--------------------------------------------------------------------------
    */

    'SA' => [ // Saudi Arabia
        'name' => 'Saudi Arabia',
        'currency' => 'SAR',
        'tax_scheme' => 'VAT',
        'tax_authority' => 'ZATCA',
        'compliance_system' => 'zatca', // CompliPay integration

        'tax_rates' => [
            'standard' => 15.0,
            'zero' => 0.0,
            'exempt' => 0.0,
        ],

        'tax_categories' => [
            'S' => ['name' => 'Standard Rate', 'rate' => 15.0, 'code' => 'S'],
            'Z' => ['name' => 'Zero Rate', 'rate' => 0.0, 'code' => 'Z'],
            'E' => ['name' => 'Exempt', 'rate' => 0.0, 'code' => 'E'],
            'O' => ['name' => 'Out of Scope', 'rate' => 0.0, 'code' => 'O'],
        ],

        // Zero-rated supplies (exports, international transport, qualified medicines)
        'zero_rated_categories' => [
            'exports',
            'international_transport',
            'qualified_medicines',
            'qualified_medical_equipment',
        ],

        // Exempt supplies (financial services, residential rent, local transport)
        'exempt_categories' => [
            'financial_services',
            'life_insurance',
            'residential_rent',
            'local_passenger_transport',
        ],

        'invoice_requirements' => [
            'tax_number_required' => true,
            'tax_number_format' => '/^3\d{14}$/', // 15 digits starting with 3
            'qr_code_required' => true,
            'simplified_invoice_threshold' => 1000, // SAR
            'credit_note_reason_required' => true,
        ],

        'fiscal_year' => [
            'type' => 'gregorian', // or 'hijri'
            'default_start' => '01-01',
        ],

        'decimal_places' => [
            'currency' => 2,
            'quantity' => 3,
            'unit_price' => 2,
            'tax_rate' => 2,
        ],

        'business_rules' => [
            'b2b_tax_number_required' => true,
            'max_invoice_age_days' => 15, // For ZATCA submission
            'retention_years' => 6,
        ],
    ],

    'AE' => [ // United Arab Emirates
        'name' => 'United Arab Emirates',
        'currency' => 'AED',
        'tax_scheme' => 'VAT',
        'tax_authority' => 'FTA',
        'compliance_system' => 'fta',

        'tax_rates' => [
            'standard' => 5.0,
            'zero' => 0.0,
            'exempt' => 0.0,
        ],

        'tax_categories' => [
            'S' => ['name' => 'Standard Rate', 'rate' => 5.0, 'code' => 'SR'],
            'Z' => ['name' => 'Zero Rate', 'rate' => 0.0, 'code' => 'ZR'],
            'E' => ['name' => 'Exempt', 'rate' => 0.0, 'code' => 'EX'],
            'O' => ['name' => 'Out of Scope', 'rate' => 0.0, 'code' => 'OS'],
            'RC' => ['name' => 'Reverse Charge', 'rate' => 5.0, 'code' => 'RC'],
        ],

        'zero_rated_categories' => [
            'exports',
            'international_transport',
            'first_sale_residential',
            'crude_oil_gas',
            'education_services',
            'healthcare_services',
        ],

        'exempt_categories' => [
            'financial_services',
            'residential_buildings',
            'bare_land',
            'local_passenger_transport',
        ],

        'invoice_requirements' => [
            'tax_number_required' => true,
            'tax_number_format' => '/^\d{15}$/', // 15 digits TRN
            'qr_code_required' => false,
            'simplified_invoice_threshold' => 10000, // AED
        ],

        'decimal_places' => [
            'currency' => 2,
            'quantity' => 3,
            'unit_price' => 2,
        ],

        'business_rules' => [
            'vat_return_period' => 'quarterly', // or monthly for large businesses
            'retention_years' => 5,
        ],
    ],

    'QA' => [ // Qatar (No VAT yet, but preparing)
        'name' => 'Qatar',
        'currency' => 'QAR',
        'tax_scheme' => 'none', // No VAT implemented yet
        'tax_authority' => 'GTA',

        'tax_rates' => [
            'standard' => 0.0,
        ],

        'decimal_places' => [
            'currency' => 2,
            'quantity' => 3,
        ],
    ],

    'OM' => [ // Oman
        'name' => 'Oman',
        'currency' => 'OMR',
        'tax_scheme' => 'VAT',
        'tax_authority' => 'OTA',
        'compliance_system' => 'ota',

        'tax_rates' => [
            'standard' => 5.0,
            'zero' => 0.0,
            'exempt' => 0.0,
        ],

        'tax_categories' => [
            'S' => ['name' => 'Standard Rate', 'rate' => 5.0],
            'Z' => ['name' => 'Zero Rate', 'rate' => 0.0],
            'E' => ['name' => 'Exempt', 'rate' => 0.0],
        ],

        'zero_rated_categories' => [
            'exports',
            'international_transport',
            'basic_food_items', // 94 items list
            'education',
            'healthcare',
        ],

        'decimal_places' => [
            'currency' => 3, // OMR has 3 decimal places
            'quantity' => 3,
        ],
    ],

    'BH' => [ // Bahrain
        'name' => 'Bahrain',
        'currency' => 'BHD',
        'tax_scheme' => 'VAT',
        'tax_authority' => 'NBR',
        'compliance_system' => 'nbr',

        'tax_rates' => [
            'standard' => 10.0, // Increased from 5% to 10% in 2022
            'zero' => 0.0,
            'exempt' => 0.0,
        ],

        'tax_categories' => [
            'S' => ['name' => 'Standard Rate', 'rate' => 10.0],
            'Z' => ['name' => 'Zero Rate', 'rate' => 0.0],
            'E' => ['name' => 'Exempt', 'rate' => 0.0],
        ],

        'decimal_places' => [
            'currency' => 3, // BHD has 3 decimal places
            'quantity' => 3,
        ],
    ],

    'KW' => [ // Kuwait (No VAT)
        'name' => 'Kuwait',
        'currency' => 'KWD',
        'tax_scheme' => 'none',

        'tax_rates' => [
            'standard' => 0.0,
        ],

        'decimal_places' => [
            'currency' => 3, // KWD has 3 decimal places
            'quantity' => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | India - GST Compliance
    |--------------------------------------------------------------------------
    */

    'IN' => [ // India
        'name' => 'India',
        'currency' => 'INR',
        'tax_scheme' => 'GST',
        'tax_authority' => 'GSTN',
        'compliance_system' => 'gst',

        'tax_rates' => [
            'rate_0' => 0.0,
            'rate_5' => 5.0,
            'rate_12' => 12.0,
            'rate_18' => 18.0,
            'rate_28' => 28.0,
        ],

        'tax_categories' => [
            'GST0' => ['name' => 'GST 0%', 'rate' => 0.0],
            'GST5' => ['name' => 'GST 5%', 'rate' => 5.0],
            'GST12' => ['name' => 'GST 12%', 'rate' => 12.0],
            'GST18' => ['name' => 'GST 18%', 'rate' => 18.0],
            'GST28' => ['name' => 'GST 28%', 'rate' => 28.0],
            'EXEMPT' => ['name' => 'Exempt', 'rate' => 0.0],
            'NIL' => ['name' => 'Nil Rated', 'rate' => 0.0],
        ],

        // GST splits into CGST+SGST (intra-state) or IGST (inter-state)
        'gst_components' => [
            'intra_state' => ['CGST', 'SGST'], // Central + State
            'inter_state' => ['IGST'], // Integrated
            'union_territory' => ['CGST', 'UTGST'], // Central + UT
        ],

        'states' => [
            '01' => 'Jammu & Kashmir',
            '02' => 'Himachal Pradesh',
            '03' => 'Punjab',
            '04' => 'Chandigarh',
            '05' => 'Uttarakhand',
            '06' => 'Haryana',
            '07' => 'Delhi',
            '08' => 'Rajasthan',
            '09' => 'Uttar Pradesh',
            '10' => 'Bihar',
            '11' => 'Sikkim',
            '12' => 'Arunachal Pradesh',
            '13' => 'Nagaland',
            '14' => 'Manipur',
            '15' => 'Mizoram',
            '16' => 'Tripura',
            '17' => 'Meghalaya',
            '18' => 'Assam',
            '19' => 'West Bengal',
            '20' => 'Jharkhand',
            '21' => 'Odisha',
            '22' => 'Chattisgarh',
            '23' => 'Madhya Pradesh',
            '24' => 'Gujarat',
            '26' => 'Dadra & Nagar Haveli and Daman & Diu',
            '27' => 'Maharashtra',
            '28' => 'Andhra Pradesh (Old)',
            '29' => 'Karnataka',
            '30' => 'Goa',
            '31' => 'Lakshadweep',
            '32' => 'Kerala',
            '33' => 'Tamil Nadu',
            '34' => 'Puducherry',
            '35' => 'Andaman & Nicobar Islands',
            '36' => 'Telangana',
            '37' => 'Andhra Pradesh',
            '38' => 'Ladakh',
        ],

        'invoice_requirements' => [
            'gstin_required' => true,
            'gstin_format' => '/^\d{2}[A-Z]{5}\d{4}[A-Z]{1}[A-Z\d]{1}[Z]{1}[A-Z\d]{1}$/',
            'hsn_required' => true, // HSN/SAC code mandatory
            'hsn_digits' => [
                'turnover_below_5cr' => 4, // 4 digits HSN
                'turnover_above_5cr' => 6, // 6 digits HSN
            ],
            'place_of_supply_required' => true,
            'irn_required' => true, // Invoice Reference Number for e-invoicing
            'eway_bill_threshold' => 50000, // INR
        ],

        'eway_bill' => [
            'enabled' => true,
            'threshold' => 50000, // INR
            'validity_km_per_day' => 100, // For normal cargo
            'validity_km_per_day_odc' => 20, // Over dimensional cargo
            'max_extension_times' => 8,
        ],

        'tds_tcs' => [
            'tds_threshold' => 50, // Lakhs per annum
            'tds_rate' => 1.0, // 1% TDS on goods, 2% on services
            'tcs_rate' => 1.0, // 1% TCS on receivables > 50 lakhs
        ],

        'composition_scheme' => [
            'turnover_limit' => 15000000, // 1.5 Crore
            'tax_rate_goods' => 1.0,
            'tax_rate_restaurant' => 5.0,
            'restrictions' => [
                'no_inter_state_supply',
                'no_ecommerce_supply',
                'no_input_tax_credit',
            ],
        ],

        'decimal_places' => [
            'currency' => 2,
            'quantity' => 3,
            'unit_price' => 2,
        ],

        'business_rules' => [
            'e_invoice_turnover_threshold' => 50000000, // 5 Crore
            'retention_years' => 8,
            'gstr1_due_date' => 11, // 11th of next month
            'gstr3b_due_date' => 20, // 20th of next month
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Settings
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'tax_inclusive' => false, // Prices are tax-exclusive by default
        'round_tax' => true,
        'round_total' => true,
        'rounding_method' => 'half_up', // half_up, half_down, floor, ceil
        'rounding_precision' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | International Trade
    |--------------------------------------------------------------------------
    */

    'international' => [
        'export' => [
            'tax_rate' => 0.0, // Exports are zero-rated
            'documentation_required' => [
                'shipping_bill',
                'bill_of_lading',
                'invoice',
                'packing_list',
            ],
        ],
        'import' => [
            'customs_duty_applies' => true,
            'reverse_charge_vat' => true, // In some jurisdictions
        ],
    ],
];
