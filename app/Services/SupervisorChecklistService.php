<?php

namespace App\Services;

use App\Models\SupervisorChecklistEntry;
use App\Models\SupervisorChecklistQuestion;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

class SupervisorChecklistService
{
    /**
     * The duration of each checklist block in hours.
     */
    private const int BLOCK_HOURS = 1;

    /**
     * The number of blocks in a day.
     */
    private const int BLOCKS_PER_DAY = 24;

    /**
     * Get the fixed one-hour block that contains the given moment.
     *
     * @return array<string, Carbon>
     */
    public function blockContaining(CarbonInterface $moment): array
    {
        $start = $moment->copy()->startOfDay()->addHours((int) floor($moment->hour / self::BLOCK_HOURS) * self::BLOCK_HOURS);

        return [
            'start' => $start,
            'end' => $start->copy()->addHours(self::BLOCK_HOURS),
        ];
    }

    /**
     * Get the current one-hour block.
     *
     * @return array<string, Carbon>
     */
    public function currentBlock(): array
    {
        return $this->blockContaining(Date::now());
    }

    /**
     * Get the block that most recently ended.
     *
     * @return array<string, Carbon>
     */
    public function previousCompletedBlock(): array
    {
        $current = $this->currentBlock();

        return [
            'start' => $current['start']->copy()->subHours(self::BLOCK_HOURS),
            'end' => $current['start'],
        ];
    }

    /**
     * Get all blocks for the given calendar date.
     *
     * @return \Illuminate\Support\Collection<int, object{start: Carbon, end: Carbon}>
     */
    public function blocksForDate(CarbonInterface $date): \Illuminate\Support\Collection
    {
        $startOfDay = $date->copy()->startOfDay();

        return \Illuminate\Support\Collection::times(self::BLOCKS_PER_DAY, function (int $index) use ($startOfDay): object {
            $start = $startOfDay->copy()->addHours(($index - 1) * self::BLOCK_HOURS);

            return (object) [
                'start' => $start,
                'end' => $start->copy()->addHours(self::BLOCK_HOURS),
            ];
        });
    }

    /**
     * Determine whether the supervisor has submitted an entry for the given block.
     */
    public function hasEntryForBlock(User $supervisor, CarbonInterface $start, CarbonInterface $end): bool
    {
        return SupervisorChecklistEntry::where('user_id', $supervisor->id)
            ->where('block_starts_at', $start)
            ->where('block_ends_at', $end)
            ->exists();
    }

    /**
     * Get the active questions ordered for display.
     *
     * @return Collection<int, SupervisorChecklistQuestion>
     */
    public function activeQuestions(): Collection
    {
        return SupervisorChecklistQuestion::with('options')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }
}
