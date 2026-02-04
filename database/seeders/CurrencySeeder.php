<?php

namespace Database\Seeders;

use App\Models\Accounting\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            // GCC Currencies
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'AED', 'decimal_places' => 2],
            ['code' => 'QAR', 'name' => 'Qatari Riyal', 'symbol' => 'QR', 'decimal_places' => 2],
            ['code' => 'OMR', 'name' => 'Omani Rial', 'symbol' => 'OMR', 'decimal_places' => 3],
            ['code' => 'BHD', 'name' => 'Bahraini Dinar', 'symbol' => 'BD', 'decimal_places' => 3],
            ['code' => 'KWD', 'name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'decimal_places' => 3],

            // Indian Currency
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'decimal_places' => 2],

            // Major World Currencies
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimal_places' => 2],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimal_places' => 2],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimal_places' => 0],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'decimal_places' => 2],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }

        $this->command->info('Currencies seeded successfully.');
    }
}
