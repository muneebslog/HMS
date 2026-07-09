<?php

namespace Database\Factories;

use App\Enums\SmsStatus;
use App\Models\Doctor;
use App\Models\SmsLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsLog>
 */
class SmsLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<SmsLog>
     */
    protected $model = SmsLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory(),
            'phone' => '+923001234567',
            'token_number' => $this->faker->numberBetween(1, 500),
            'message' => $this->faker->sentence(),
            'status' => $this->faker->randomElement(SmsStatus::values()),
            'provider_response' => null,
            'sent_at' => null,
        ];
    }

    /**
     * Indicate that the SMS is queued.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SmsStatus::Queued,
            'sent_at' => null,
        ]);
    }

    /**
     * Indicate that the SMS was sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SmsStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    /**
     * Indicate that the SMS failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SmsStatus::Failed,
            'provider_response' => $this->faker->sentence(),
            'sent_at' => null,
        ]);
    }
}
