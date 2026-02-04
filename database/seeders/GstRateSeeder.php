<?php

namespace Database\Seeders;

use App\Models\Tax\HsnSacCode;
use Illuminate\Database\Seeder;

class GstRateSeeder extends Seeder
{
    /**
     * Seed common HSN/SAC codes for India GST.
     */
    public function run(): void
    {
        $codes = [
            // Common HSN Codes for Goods
            ['code' => '0101', 'description' => 'Live horses, asses, mules and hinnies', 'gst_rate' => 0, 'type' => 'goods'],
            ['code' => '0201', 'description' => 'Meat of bovine animals, fresh or chilled', 'gst_rate' => 0, 'type' => 'goods'],
            ['code' => '0401', 'description' => 'Milk and cream', 'gst_rate' => 0, 'type' => 'goods'],
            ['code' => '0701', 'description' => 'Potatoes, fresh or chilled', 'gst_rate' => 0, 'type' => 'goods'],
            ['code' => '0713', 'description' => 'Dried leguminous vegetables', 'gst_rate' => 0, 'type' => 'goods'],
            ['code' => '1001', 'description' => 'Wheat and meslin', 'gst_rate' => 0, 'type' => 'goods'],
            ['code' => '1006', 'description' => 'Rice', 'gst_rate' => 0, 'type' => 'goods'],

            // 5% GST
            ['code' => '0402', 'description' => 'Milk and cream, concentrated', 'gst_rate' => 5, 'type' => 'goods'],
            ['code' => '0405', 'description' => 'Butter and other fats derived from milk', 'gst_rate' => 5, 'type' => 'goods'],
            ['code' => '1905', 'description' => 'Bread, pastry, cakes, biscuits', 'gst_rate' => 5, 'type' => 'goods'],
            ['code' => '2201', 'description' => 'Waters including mineral waters', 'gst_rate' => 5, 'type' => 'goods'],

            // 12% GST
            ['code' => '1704', 'description' => 'Sugar confectionery', 'gst_rate' => 12, 'type' => 'goods'],
            ['code' => '1806', 'description' => 'Chocolate and food preparations containing cocoa', 'gst_rate' => 12, 'type' => 'goods'],
            ['code' => '2106', 'description' => 'Food preparations not elsewhere specified', 'gst_rate' => 12, 'type' => 'goods'],
            ['code' => '3304', 'description' => 'Beauty or make-up preparations', 'gst_rate' => 12, 'type' => 'goods'],
            ['code' => '4901', 'description' => 'Printed books, brochures, leaflets', 'gst_rate' => 12, 'type' => 'goods'],

            // 18% GST
            ['code' => '2202', 'description' => 'Waters including sweetened or flavoured', 'gst_rate' => 18, 'type' => 'goods'],
            ['code' => '3305', 'description' => 'Preparations for use on the hair', 'gst_rate' => 18, 'type' => 'goods'],
            ['code' => '3401', 'description' => 'Soap', 'gst_rate' => 18, 'type' => 'goods'],
            ['code' => '3923', 'description' => 'Articles for conveyance or packing of goods, of plastics', 'gst_rate' => 18, 'type' => 'goods'],
            ['code' => '8471', 'description' => 'Computers and peripheral units', 'gst_rate' => 18, 'type' => 'goods'],
            ['code' => '8517', 'description' => 'Telephone sets, including smartphones', 'gst_rate' => 18, 'type' => 'goods'],
            ['code' => '8528', 'description' => 'Monitors, projectors, TVs', 'gst_rate' => 18, 'type' => 'goods'],
            ['code' => '9403', 'description' => 'Other furniture and parts thereof', 'gst_rate' => 18, 'type' => 'goods'],

            // 28% GST
            ['code' => '2402', 'description' => 'Cigars, cheroots, cigarillos and cigarettes', 'gst_rate' => 28, 'type' => 'goods'],
            ['code' => '2403', 'description' => 'Other manufactured tobacco', 'gst_rate' => 28, 'type' => 'goods'],
            ['code' => '8703', 'description' => 'Motor cars and other motor vehicles', 'gst_rate' => 28, 'type' => 'goods'],
            ['code' => '8711', 'description' => 'Motorcycles and cycles', 'gst_rate' => 28, 'type' => 'goods'],
            ['code' => '9504', 'description' => 'Video games, articles for funfair games', 'gst_rate' => 28, 'type' => 'goods'],

            // Common SAC Codes for Services (18% GST)
            ['code' => '9954', 'description' => 'Construction services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9961', 'description' => 'Financial and related services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9962', 'description' => 'Insurance and pension services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9963', 'description' => 'Real estate services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9964', 'description' => 'Rental/leasing services without operator', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9965', 'description' => 'Leasing or rental services with operator', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9966', 'description' => 'Supporting transport services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9967', 'description' => 'Supporting services in transport', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9971', 'description' => 'Postal and courier services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9972', 'description' => 'Accommodation, food and beverage services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9973', 'description' => 'Publishing, broadcasting and content supply', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9981', 'description' => 'Research and development services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9982', 'description' => 'Legal and accounting services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9983', 'description' => 'Other professional services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9984', 'description' => 'Telecommunications services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9985', 'description' => 'Support services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9986', 'description' => 'Support services to agriculture', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9987', 'description' => 'Maintenance and repair services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9988', 'description' => 'Manufacturing services on goods owned by others', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9991', 'description' => 'Public administration services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9992', 'description' => 'Education services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9993', 'description' => 'Human health and social care services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9994', 'description' => 'Sewage and waste collection services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9995', 'description' => 'Services of membership organizations', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9996', 'description' => 'Recreational, cultural and sporting services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9997', 'description' => 'Other services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9998', 'description' => 'Domestic services', 'gst_rate' => 18, 'type' => 'service'],
            ['code' => '9999', 'description' => 'Services provided by extra-territorial organizations', 'gst_rate' => 18, 'type' => 'service'],
        ];

        foreach ($codes as $code) {
            HsnSacCode::firstOrCreate(
                ['code' => $code['code']],
                $code
            );
        }
    }
}
