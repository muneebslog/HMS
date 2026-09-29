<?php

namespace Database\Factories;

use App\Models\PartnerLabReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerLabReport>
 */
class PartnerLabReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $guid = strtoupper(fake()->uuid());

        return [
            'source' => PartnerLabReport::SOURCE_TEST_ZONE,
            'partner_test_id' => (string) fake()->unique()->numberBetween(2000000, 2999999),
            'partner_case_no' => '0926-'.fake()->numberBetween(10000, 99999),
            'partner_patient_no' => fake()->numberBetween(1000000, 1999999).'-TZDC',
            'patient_name' => strtoupper(fake()->firstName()),
            'patient_age' => fake()->numberBetween(1, 90).' Year(s)',
            'patient_gender' => fake()->randomElement(['Male', 'Female']),
            'registered_at' => now(),
            'reference' => 'Mohsin Medical Complex (LHR)',
            'test_code' => (string) fake()->numberBetween(1000, 9999),
            'test_name' => 'Hemoglobin A1C (HBA1C)',
            'status' => 'Approved Report',
            'report_url' => "https://testzone.nextstep.pk/offlineReports/labreport.aspx?id={$guid}",
            'ready_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    /**
     * A test whose report is not ready yet.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Test In Process',
            'ready_at' => null,
        ]);
    }
}
