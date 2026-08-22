<?php

namespace Database\Seeders;

use App\Models\DutyShiftTemplate;
use Illuminate\Database\Seeder;

class DutyShiftTemplateSeeder extends Seeder
{
    /**
     * Seed default health aide duty shift templates.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Morning',
                'start_time' => '07:00:00',
                'end_time' => '15:00:00',
            ],
            [
                'name' => 'Evening',
                'start_time' => '15:00:00',
                'end_time' => '23:00:00',
            ],
            [
                'name' => 'Night',
                'start_time' => '23:00:00',
                'end_time' => '07:00:00',
            ],
        ];

        foreach ($templates as $template) {
            DutyShiftTemplate::query()->firstOrCreate(
                ['name' => $template['name']],
                [
                    'start_time' => $template['start_time'],
                    'end_time' => $template['end_time'],
                    'grace_minutes_in' => 15,
                    'grace_minutes_out' => 10,
                    'break_minutes' => 0,
                    'is_active' => true,
                ],
            );
        }
    }
}
