<?php

namespace Database\Seeders;

use App\Models\Supply;
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

        $categoryMap = [
            'C1' => 'emergency_drugs',
            'C2' => 'iv_access',
            'C3' => 'airway',
            'C4' => 'consumables',
            'C5' => 'fluids',
        ];

        foreach ($this->crashCartDrawers() as $drawerKey => $drawer) {
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

    /**
     * Crash cart drawer items used to seed ER trolley supplies.
     *
     * @return array<string, array{label: string, items: array<string, array{label: string, par: int|null}>}>
     */
    private function crashCartDrawers(): array
    {
        return [
            'C1' => [
                'label' => 'Drawer 1: Emergency Drugs',
                'items' => [
                    'adrenaline' => ['label' => 'Adrenaline', 'par' => 5],
                    'atropine' => ['label' => 'Atropine', 'par' => 5],
                    'dopamine' => ['label' => 'Dopamine', 'par' => 2],
                    'mgso4' => ['label' => 'MgSO4', 'par' => 2],
                    'calcium_gluconate' => ['label' => 'Calcium Gluconate', 'par' => 5],
                    'hydralazine' => ['label' => 'Hydralazine', 'par' => 1],
                    'labetalol' => ['label' => 'Labetalol', 'par' => 2],
                    'dexamethasone' => ['label' => 'Dexamethasone', 'par' => 5],
                    'lasix' => ['label' => 'Lasix', 'par' => 5],
                    'ondansetron' => ['label' => 'Ondansetron', 'par' => 5],
                    'tranexamic_acid' => ['label' => 'Tranexamic Acid', 'par' => 5],
                    'hydrocortisone' => ['label' => 'Hydrocortisone', 'par' => 2],
                ],
            ],
            'C2' => [
                'label' => 'Drawer 2: IV Access & Diagnostics',
                'items' => [
                    'iv_cannula_18g' => ['label' => 'IV Cannula 18G', 'par' => null],
                    'iv_cannula_20g' => ['label' => 'IV Cannula 20G', 'par' => null],
                    'iv_cannula_22g' => ['label' => 'IV Cannula 22G', 'par' => null],
                    'iv_cannula_24g' => ['label' => 'IV Cannula 24G', 'par' => null],
                    'iv_sets' => ['label' => 'IV Sets', 'par' => null],
                    'burette_set' => ['label' => 'Burette Set', 'par' => null],
                    'steri_strips' => ['label' => 'Steri-Strips', 'par' => null],
                    'syringes' => ['label' => 'Syringes (Assorted)', 'par' => null],
                    'ent_box' => ['label' => 'ENT Box', 'par' => null],
                    'pulse_oximeter_probe' => ['label' => 'Pulse Oximeter Probe', 'par' => null],
                ],
            ],
            'C3' => [
                'label' => 'Drawer 3: Airway & Tubes',
                'items' => [
                    'airway_guedel' => ['label' => 'Airway (Guedel)', 'par' => null],
                    'endotracheal_tube' => ['label' => 'Endotracheal Tube', 'par' => null],
                    'oxygen_cannula' => ['label' => 'Oxygen Cannula', 'par' => null],
                    'foley_catheter' => ['label' => 'Foley Catheter', 'par' => null],
                    'nelaton_catheter' => ['label' => 'Nelaton Catheter', 'par' => null],
                    'feeding_tube' => ['label' => 'Feeding Tube', 'par' => null],
                ],
            ],
            'C4' => [
                'label' => 'Drawer 4: Consumables',
                'items' => [
                    'surgical_gloves' => ['label' => 'Surgical Gloves', 'par' => null],
                    'examination_gloves' => ['label' => 'Examination Gloves', 'par' => null],
                    'ear_syringe' => ['label' => 'Ear Syringe', 'par' => null],
                    'crash_ambu_bag' => ['label' => 'Ambu Bag', 'par' => null],
                    'oxygen_mask' => ['label' => 'Oxygen Mask', 'par' => null],
                    'suction_set' => ['label' => 'Suction Set', 'par' => null],
                    'micropore_tape' => ['label' => 'Micropore Tape', 'par' => null],
                    'caps' => ['label' => 'Caps', 'par' => null],
                ],
            ],
            'C5' => [
                'label' => 'Drawer 5: IV Fluids',
                'items' => [
                    'ns_100' => ['label' => 'NS 100 mL', 'par' => null],
                    'ns_500' => ['label' => 'NS 500 mL', 'par' => null],
                    'ns_1000' => ['label' => 'NS 1000 mL', 'par' => null],
                    'rl_500' => ['label' => 'RL 500 mL', 'par' => null],
                    'rl_1000' => ['label' => 'RL 1000 mL', 'par' => null],
                    'dextrose_flagyl' => ['label' => 'Dextrose / Flagyl', 'par' => null],
                ],
            ],
        ];
    }
}
