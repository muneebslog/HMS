<?php

namespace Database\Seeders;

use App\Enums\TokenResetType;
use App\Models\Service;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $services = [
            [
                'name' => 'consultation',
                'is_standalone' => false,
                'token_reset_type' => TokenResetType::Daily,
            ],
            [
                'name' => 'IM Injection',
                'is_standalone' => true,
                'token_reset_type' => TokenResetType::Shift,
            ],
            [
                'name' => 'IV Drip',
                'is_standalone' => true,
                'token_reset_type' => TokenResetType::Shift,
            ],
            [
                'name' => 'Bandage Dressing',
                'is_standalone' => true,
                'token_reset_type' => TokenResetType::Shift,
            ],
            [
                'name' => 'General Checkup',
                'is_standalone' => false,
                'token_reset_type' => TokenResetType::Shift,
            ],
        ];

        foreach ($services as $service) {
            Service::updateOrCreate(
                ['name' => $service['name']],
                $service
            );
        }
    }
}
