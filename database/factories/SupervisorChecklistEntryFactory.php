<?php

namespace Database\Factories;

use App\Models\SupervisorChecklistEntry;
use App\Models\User;
use App\Services\SupervisorChecklistService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupervisorChecklistEntry>
 */
class SupervisorChecklistEntryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<SupervisorChecklistEntry>
     */
    protected $model = SupervisorChecklistEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $block = app(SupervisorChecklistService::class)->currentBlock();

        return [
            'user_id' => User::factory()->receptionist(),
            'block_starts_at' => $block['start'],
            'block_ends_at' => $block['end'],
            'submitted_at' => now(),
        ];
    }

    /**
     * Set the entry's block range.
     */
    public function forBlock(CarbonInterface $start, CarbonInterface $end): static
    {
        return $this->state(fn (array $attributes) => [
            'block_starts_at' => $start,
            'block_ends_at' => $end,
        ]);
    }

    /**
     * Set the user who owns the entry.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Set the supervisor who owns the entry.
     *
     * @deprecated Use forUser() instead.
     */
    public function forSupervisor(User $user): static
    {
        return $this->forUser($user);
    }
}
