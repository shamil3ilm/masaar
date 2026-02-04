<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Settings
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'currency' => env('ERP_DEFAULT_CURRENCY', 'SAR'),
        'timezone' => env('ERP_DEFAULT_TIMEZONE', 'Asia/Riyadh'),
        'language' => env('ERP_DEFAULT_LANGUAGE', 'en'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    */
    'security' => [
        'login' => [
            'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
            'lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 15),
        ],
        'password' => [
            'min_length' => (int) env('PASSWORD_MIN_LENGTH', 8),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Countries
    |--------------------------------------------------------------------------
    */
    'countries' => [
        'SA' => [
            'name' => 'Saudi Arabia',
            'currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'tax_scheme' => 'VAT',
            'vat_rate' => 15,
        ],
        'AE' => [
            'name' => 'United Arab Emirates',
            'currency' => 'AED',
            'timezone' => 'Asia/Dubai',
            'tax_scheme' => 'VAT',
            'vat_rate' => 5,
        ],
        'QA' => [
            'name' => 'Qatar',
            'currency' => 'QAR',
            'timezone' => 'Asia/Riyadh',
            'tax_scheme' => 'VAT',
            'vat_rate' => 0, // No VAT yet
        ],
        'OM' => [
            'name' => 'Oman',
            'currency' => 'OMR',
            'timezone' => 'Asia/Dubai',
            'tax_scheme' => 'VAT',
            'vat_rate' => 5,
        ],
        'BH' => [
            'name' => 'Bahrain',
            'currency' => 'BHD',
            'timezone' => 'Asia/Riyadh',
            'tax_scheme' => 'VAT',
            'vat_rate' => 10,
        ],
        'KW' => [
            'name' => 'Kuwait',
            'currency' => 'KWD',
            'timezone' => 'Asia/Riyadh',
            'tax_scheme' => 'VAT',
            'vat_rate' => 0, // No VAT yet
        ],
        'IN' => [
            'name' => 'India',
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'tax_scheme' => 'GST',
            'gst_rates' => [0, 5, 12, 18, 28],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Currencies
    |--------------------------------------------------------------------------
    */
    'currencies' => [
        'SAR' => ['name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimals' => 2],
        'AED' => ['name' => 'UAE Dirham', 'symbol' => 'AED', 'decimals' => 2],
        'QAR' => ['name' => 'Qatari Riyal', 'symbol' => 'QR', 'decimals' => 2],
        'OMR' => ['name' => 'Omani Rial', 'symbol' => 'OMR', 'decimals' => 3],
        'BHD' => ['name' => 'Bahraini Dinar', 'symbol' => 'BD', 'decimals' => 3],
        'KWD' => ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'decimals' => 3],
        'INR' => ['name' => 'Indian Rupee', 'symbol' => '₹', 'decimals' => 2],
        'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2],
        'EUR' => ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2],
    ],
];
