<?php

namespace Database\Seeders;

use App\Models\DutyLocation;
use Illuminate\Database\Seeder;

class DutyLocationSeeder extends Seeder
{
    /**
     * Seed default duty locations for health aide roster.
     */
    public function run(): void
    {
        $locations = [
            'ER Station',
            'Drip Station',
            'Stock Station',
            'Reception',
            'Ward',
            'Emergency Department',
        ];

        foreach ($locations as $index => $name) {
            DutyLocation::query()->firstOrCreate(
                ['name' => $name],
                [
                    'sort_order' => $index,
                    'is_active' => true,
                ],
            );
        }
    }
}
