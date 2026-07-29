<?php

namespace Database\Factories;

use App\Enums\AdminReportStatus;
use App\Models\AdminReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminReport>
 */
class AdminReportFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<AdminReport>
     */
    protected $model = AdminReport::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'subject' => $this->faker->sentence(4),
            'status' => AdminReportStatus::Open,
            'last_message_at' => now(),
        ];
    }

    /**
     * Indicate that the report is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AdminReportStatus::Closed,
        ]);
    }
}
