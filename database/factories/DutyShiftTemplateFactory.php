<?php

namespace Database\Factories;

use App\Models\DutyShiftTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DutyShiftTemplate>
 */
class DutyShiftTemplateFactory extends Factory
{
    protected $model = DutyShiftTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Morning',
            'start_time' => '07:00',
            'end_time' => '15:00',
            'grace_minutes_in' => 15,
            'grace_minutes_out' => 10,
            'break_minutes' => 0,
            'is_active' => true,
        ];
    }

    public function night(): static
    {
        return $this->state(fn () => [
            'name' => 'Night',
            'start_time' => '23:00',
            'end_time' => '07:00',
        ]);
    }
}
