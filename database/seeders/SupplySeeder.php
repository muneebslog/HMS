<?php

namespace Database\Seeders;

use App\Models\Supply;
use App\Services\EmergencyDepartmentLogDefinition;
use App\Services\InventoryStockService;
use Illuminate\Database\Seeder;

class SupplySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stock = app(InventoryStockService::class);
        $definition = app(EmergencyDepartmentLogDefinition::class);

        $categoryMap = [
            'C1' => 'emergency_drugs',
            'C2' => 'iv_access',
            'C3' => 'airway',
            'C4' => 'consumables',
            'C5' => 'fluids',
        ];

        foreach ($definition->crashCartDrawers() as $drawerKey => $drawer) {
            $category = $categoryMap[$drawerKey] ?? 'consumables';

            foreach ($drawer['items'] as $itemKey => $item) {
                $supply = Supply::query()->firstOrCreate(
                    ['name' => $item['label']],
                    [
                        'short_form' => strtoupper(str_replace('_', ' ', $itemKey)),
                        'category' => $category,
                        'unit' => 'piece',
                        'default_par' => $item['par'],
                        'is_active' => true,
                    ]
                );

                if ($supply->wasRecentlyCreated) {
                    continue;
                }
            }
        }

        $extraSupplies = [
            ['name' => 'Cotton', 'category' => 'consumables', 'unit' => 'pack', 'default_par' => 10],
            ['name' => 'Gauze', 'category' => 'consumables', 'unit' => 'pack', 'default_par' => 10],
            ['name' => 'Alcohol Swabs', 'category' => 'consumables', 'unit' => 'box', 'default_par' => 5],
        ];

        foreach ($extraSupplies as $extra) {
            Supply::query()->firstOrCreate(
                ['name' => $extra['name']],
                [
                    'short_form' => null,
                    'category' => $extra['category'],
                    'unit' => $extra['unit'],
                    'default_par' => $extra['default_par'],
                    'is_active' => true,
                ]
            );
        }

        Supply::query()->each(function (Supply $supply) use ($stock): void {
            if ($supply->stockBalances()->exists()) {
                return;
            }

            $stock->initializeBalances($supply);
        });
    }
}
