<?php

namespace Database\Factories;

use App\Models\NurseQuestionnaire;
use App\Models\NurseQuestionnaireEntry;
use App\Models\User;
use App\Services\NurseQuestionnaireService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NurseQuestionnaireEntry>
 */
class NurseQuestionnaireEntryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<NurseQuestionnaireEntry>
     */
    protected $model = NurseQuestionnaireEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $block = app(NurseQuestionnaireService::class)->currentBlock(
            new NurseQuestionnaire(['interval_hours' => 2])
        );

        return [
            'questionnaire_id' => NurseQuestionnaire::factory(),
            'user_id' => User::factory()->inchargeNurse(),
            'block_starts_at' => $block['start'],
            'block_ends_at' => $block['end'],
            'submitted_at' => now(),
        ];
    }

    /**
     * Set the entry's questionnaire and matching block range.
     */
    public function forQuestionnaire(NurseQuestionnaire $questionnaire): static
    {
        $block = app(NurseQuestionnaireService::class)->currentBlock($questionnaire);

        return $this->state(fn (array $attributes) => [
            'questionnaire_id' => $questionnaire->id,
            'block_starts_at' => $block['start'],
            'block_ends_at' => $block['end'],
        ]);
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
}
