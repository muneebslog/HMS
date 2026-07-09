<?php

namespace Database\Seeders;

use App\Models\Doctor;
use App\Models\Service;
use App\Models\ServicePrice;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServicePriceSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $doctorAhmed = Doctor::where('name', 'Dr. Ahmed Hassan')->first();
        $doctorSara = Doctor::where('name', 'Dr. Sara Ali')->first();
        $doctorMohammed = Doctor::where('name', 'Dr. Mohammed Kareem')->first();

        $servicePrices = [
            [
                'service_name' => 'consultation',
                'doctor_name' => 'Dr. Ahmed Hassan',
                'price' => 200.00,
                'doctor_share' => 60.00,
            ],
            [
                'service_name' => 'consultation',
                'doctor_name' => 'Dr. Sara Ali',
                'price' => 250.00,
                'doctor_share' => 65.00,
            ],
            [
                'service_name' => 'consultation',
                'doctor_name' => 'Dr. Mohammed Kareem',
                'price' => 300.00,
                'doctor_share' => 70.00,
            ],
            [
                'service_name' => 'IM Injection',
                'doctor_name' => 'Dr. Ahmed Hassan',
                'price' => 50.00,
                'doctor_share' => 50.00,
            ],
            [
                'service_name' => 'IM Injection',
                'doctor_name' => 'Dr. Sara Ali',
                'price' => 60.00,
                'doctor_share' => 50.00,
            ],
            [
                'service_name' => 'IV Drip',
                'doctor_name' => 'Dr. Mohammed Kareem',
                'price' => 400.00,
                'doctor_share' => 55.00,
            ],
            [
                'service_name' => 'Bandage Dressing',
                'doctor_name' => 'Dr. Ahmed Hassan',
                'price' => 80.00,
                'doctor_share' => 45.00,
            ],
            [
                'service_name' => 'General Checkup',
                'doctor_name' => 'Dr. Sara Ali',
                'price' => 150.00,
                'doctor_share' => 60.00,
            ],
        ];

        foreach ($servicePrices as $servicePrice) {
            $service = Service::where('name', $servicePrice['service_name'])->first();
            $doctor = match ($servicePrice['doctor_name']) {
                'Dr. Ahmed Hassan' => $doctorAhmed,
                'Dr. Sara Ali' => $doctorSara,
                'Dr. Mohammed Kareem' => $doctorMohammed,
                default => null,
            };

            if ($service === null || $doctor === null) {
                continue;
            }

            ServicePrice::updateOrCreate(
                [
                    'service_id' => $service->id,
                    'doctor_id' => $doctor->id,
                ],
                [
                    'price' => $servicePrice['price'],
                    'doctor_share' => $servicePrice['doctor_share'],
                ]
            );
        }
    }
}
