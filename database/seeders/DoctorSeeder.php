<?php

namespace Database\Seeders;

use App\Models\Doctor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DoctorSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $doctors = [
            [
                'name' => 'Dr. Ahmed Hassan',
                'specialization' => 'General Medicine',
                'duty_start_time' => '18:00:00',
            ],
            [
                'name' => 'Dr. Sara Ali',
                'specialization' => 'Pediatrics',
                'duty_start_time' => '18:00:00',
            ],
            [
                'name' => 'Dr. Mohammed Kareem',
                'specialization' => 'Internal Medicine',
                'duty_start_time' => '18:00:00',
            ],
        ];

        foreach ($doctors as $doctor) {
            Doctor::updateOrCreate(
                ['name' => $doctor['name']],
                $doctor
            );
        }
    }
}
