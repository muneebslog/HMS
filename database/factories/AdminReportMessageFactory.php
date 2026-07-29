<?php

namespace Database\Factories;

use App\Models\AdminReport;
use App\Models\AdminReportMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminReportMessage>
 */
class AdminReportMessageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<AdminReportMessage>
     */
    protected $model = AdminReportMessage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admin_report_id' => AdminReport::factory(),
            'user_id' => User::factory(),
            'body' => $this->faker->paragraph(),
        ];
    }
}
