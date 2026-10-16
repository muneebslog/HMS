<?php

namespace Database\Factories;

use App\Models\AttendanceDayReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceDayReview>
 */
class AttendanceDayReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'review_date' => now()->subDay()->toDateString(),
            'reviewed_by' => null,
            'reviewed_at' => now(),
        ];
    }
}
