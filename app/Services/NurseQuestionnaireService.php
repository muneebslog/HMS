<?php

namespace App\Services;

use App\Models\NurseQuestionnaire;
use App\Models\NurseQuestionnaireEntry;
use App\Models\NurseQuestionnaireQuestion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

class NurseQuestionnaireService
{
    /**
     * Get the fixed interval block that contains the given moment.
     *
     * @return array{start: CarbonInterface, end: CarbonInterface}
     */
    public function blockContaining(NurseQuestionnaire $questionnaire, CarbonInterface $moment): array
    {
        $hours = max(1, $questionnaire->interval_hours);
        $start = $moment->copy()->startOfDay()->addHours((int) floor($moment->hour / $hours) * $hours);

        return [
            'start' => $start,
            'end' => $start->copy()->addHours($hours),
        ];
    }

    /**
     * Get the current interval block for the questionnaire.
     *
     * @return array{start: CarbonInterface, end: CarbonInterface}
     */
    public function currentBlock(NurseQuestionnaire $questionnaire): array
    {
        return $this->blockContaining($questionnaire, Date::now());
    }

    /**
     * Get the block that most recently ended.
     *
     * @return array{start: CarbonInterface, end: CarbonInterface}
     */
    public function previousCompletedBlock(NurseQuestionnaire $questionnaire): array
    {
        $current = $this->currentBlock($questionnaire);
        $hours = max(1, $questionnaire->interval_hours);

        return [
            'start' => $current['start']->copy()->subHours($hours),
            'end' => $current['start'],
        ];
    }

    /**
     * Get all blocks for the given calendar date.
     *
     * @return \Illuminate\Support\Collection<int, array{start: CarbonInterface, end: CarbonInterface}>
     */
    public function blocksForDate(NurseQuestionnaire $questionnaire, CarbonInterface $date): \Illuminate\Support\Collection
    {
        $hours = max(1, $questionnaire->interval_hours);
        $count = (int) ceil(24 / $hours);
        $startOfDay = $date->copy()->startOfDay();

        return \Illuminate\Support\Collection::times($count, function (int $index) use ($startOfDay, $hours): array {
            $start = $startOfDay->copy()->addHours(($index - 1) * $hours);

            return [
                'start' => $start,
                'end' => $start->copy()->addHours($hours),
            ];
        });
    }

    /**
     * Determine whether the nurse has submitted an entry for the given block.
     */
    public function hasEntryForBlock(User $nurse, NurseQuestionnaire $questionnaire, CarbonInterface $start, CarbonInterface $end): bool
    {
        return NurseQuestionnaireEntry::query()
            ->where('user_id', $nurse->id)
            ->where('questionnaire_id', $questionnaire->id)
            ->where('block_starts_at', $start)
            ->where('block_ends_at', $end)
            ->exists();
    }

    /**
     * Get the active questions ordered for display.
     *
     * @return Collection<int, NurseQuestionnaireQuestion>
     */
    public function activeQuestions(NurseQuestionnaire $questionnaire): Collection
    {
        return $questionnaire->questions()
            ->active()
            ->orderBy('sort_order')
            ->get();
    }
}
