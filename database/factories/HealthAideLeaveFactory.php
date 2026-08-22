<?php

namespace Database\Factories;

use App\Models\HealthAide;
use App\Models\HealthAideLeave;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<HealthAideLeave>
 */
class HealthAideLeaveFactory extends Factory
{
    protected $model = HealthAideLeave::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'health_aide_id' => HealthAide::factory(),
            'leave_date' => Carbon::today(),
            'is_informed' => false,
            'created_by' => User::factory()->admin(),
        ];
    }
}
