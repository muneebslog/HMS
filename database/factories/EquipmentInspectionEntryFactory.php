<?php

namespace Database\Factories;

use App\Enums\EquipmentInspectionArea;
use App\Enums\EquipmentInspectionShift;
use App\Models\EquipmentInspectionEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentInspectionEntry>
 */
class EquipmentInspectionEntryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<EquipmentInspectionEntry>
     */
    protected $model = EquipmentInspectionEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->inchargeNurse(),
            'area' => EquipmentInspectionArea::ConsultationRoom,
            'checklist_date' => now()->toDateString(),
            'shift' => EquipmentInspectionShift::Morning,
            'checked_by_name' => fake()->name(),
            'supervisor_name' => fake()->optional()->name(),
            'sign_off' => [
                'equip_issues' => 'no',
                'cleaning_done' => 'yes',
                'reported_to' => '',
            ],
            'submitted_at' => now(),
        ];
    }
}
