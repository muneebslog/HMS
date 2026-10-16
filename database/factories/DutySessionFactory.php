<?php

namespace Database\Factories;

use App\Models\DutySession;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DutySession>
 */
class DutySessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->subDay()->setTime(8, 0);

        return [
            'employee_id' => Employee::factory(),
            'duty_date' => $start->toDateString(),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(7),
            'in_punch_id' => null,
            'out_punch_id' => null,
            'break_minutes' => 0,
            'notes' => null,
            'confirmed_by' => null,
            'confirmed_at' => now(),
        ];
    }
}
