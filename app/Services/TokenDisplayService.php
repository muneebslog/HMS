<?php

namespace App\Services;

use App\Enums\TokenDisplayLayout;
use App\Models\QueueToken;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TokenDisplayService
{
    /**
     * Get the token currently shown for the queue, looking across its whole lane for doctor-driven queues.
     */
    public function currentToken(ServiceQueue $queue): ?QueueToken
    {
        return $this->displayedTokenQuery($this->laneQueues($queue)->modelKeys())
            ->with('patient')
            ->first();
    }

    /**
     * The latest shift (open, or just closed between shifts) and the one before it.
     *
     * @return Collection<int, Shift>
     */
    public function recentShifts(): Collection
    {
        $latestShift = Shift::current() ?? Shift::query()->latest('opened_at')->first();

        if ($latestShift === null) {
            return new Collection;
        }

        $previousShift = Shift::query()
            ->whereKeyNot($latestShift->id)
            ->where('opened_at', '<', $latestShift->opened_at)
            ->latest('opened_at')
            ->first();

        return new Collection(array_values(array_filter([$latestShift, $previousShift])));
    }

    /**
     * The queues a doctor-driven display moves through, oldest first.
     *
     * When a new shift restarts the token numbers, the previous shift's queue for the same
     * service and doctor stays in the lane so its leftover patients are called before token 1.
     * Other queues form a lane of their own.
     *
     * @return Collection<int, ServiceQueue>
     */
    public function laneQueues(ServiceQueue $queue): Collection
    {
        if (! $this->followsDoctorToken($queue)) {
            return new Collection([$queue]);
        }

        $recentShifts = $this->recentShifts();

        return ServiceQueue::query()
            ->where('service_id', $queue->service_id)
            ->where('doctor_id', $queue->doctor_id)
            ->where(function (Builder $query) use ($queue, $recentShifts): void {
                $query->whereKey($queue->id);

                foreach ($recentShifts as $shift) {
                    $query->orWhere(fn (Builder $inner) => $inner->forShift($shift));
                }
            })
            ->orderBy('opened_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The lane queue the display should show: the one holding the current token, else the newest.
     */
    public function activeQueue(ServiceQueue $queue): ServiceQueue
    {
        $lane = $this->laneQueues($queue);
        $current = $this->displayedTokenQuery($lane->modelKeys())->first();

        return $lane->firstWhere('id', $current?->service_queue_id)
            ?? $lane->last()
            ?? $queue;
    }

    /**
     * One active queue per doctor-driven lane from the recent shifts, for doctor-side token controls.
     *
     * @return Collection<int, ServiceQueue>
     */
    public function doctorDrivenQueues(): Collection
    {
        $recentShifts = $this->recentShifts();

        if ($recentShifts->isEmpty()) {
            return new Collection;
        }

        $queues = ServiceQueue::query()
            ->with(['service', 'doctor'])
            ->where('status', 'open')
            ->whereHas('service', fn (Builder $query) => $query->where('follows_doctor_token', true))
            ->where(function (Builder $query) use ($recentShifts): void {
                foreach ($recentShifts as $shift) {
                    $query->orWhere(fn (Builder $inner) => $inner->forShift($shift));
                }
            })
            ->orderBy('opened_at')
            ->get()
            ->unique(fn (ServiceQueue $queue): string => $queue->service_id.'-'.$queue->doctor_id)
            ->map(fn (ServiceQueue $queue): ServiceQueue => $this->activeQueue($queue)->loadMissing(['service', 'doctor']))
            ->values();

        return new Collection($queues->all());
    }

    /**
     * Arrived waiting tokens for a queue, ordered by token number.
     *
     * @return Collection<int, QueueToken>
     */
    public function waitingTokens(ServiceQueue $queue): Collection
    {
        return $queue->tokens()
            ->where('status', 'waiting')
            ->whereNotNull('arrived_at')
            ->orderBy('token_number')
            ->get();
    }

    /**
     * Serving tokens for a queue, ordered by token number.
     *
     * @return Collection<int, QueueToken>
     */
    public function servingTokens(ServiceQueue $queue): Collection
    {
        return $queue->tokens()
            ->where('status', 'serving')
            ->orderBy('token_number')
            ->get();
    }

    /**
     * Arrived waiting tokens across the current shift's open file-check queues.
     *
     * @return Collection<int, QueueToken>
     */
    public function fileCheckWaitingTokens(): Collection
    {
        return $this->tokensForQueues(
            $this->fileCheckQueues()->modelKeys(),
            'waiting',
        );
    }

    /**
     * Serving tokens across the current shift's open file-check queues.
     *
     * @return Collection<int, QueueToken>
     */
    public function fileCheckServingTokens(): Collection
    {
        return $this->tokensForQueues(
            $this->fileCheckQueues()->modelKeys(),
            'serving',
        );
    }

    /**
     * Open service queues for the current shift with service and doctor relations.
     *
     * Shift-reset queues are matched by the open shift id. Daily-reset queues are
     * matched by the shift's opened date so overnight shifts keep working past midnight.
     *
     * @return Collection<int, ServiceQueue>
     */
    public function openQueues(): Collection
    {
        $shift = Shift::current();

        if ($shift === null) {
            return ServiceQueue::with(['service', 'doctor'])
                ->where('status', 'open')
                ->whereDate('date', Carbon::today())
                ->orderBy('opened_at')
                ->get();
        }

        return ServiceQueue::with(['service', 'doctor'])
            ->where('status', 'open')
            ->forShift($shift)
            ->orderBy('opened_at')
            ->get();
    }

    /**
     * Open non-file-check queues for the current shift (primary board picker).
     *
     * @return Collection<int, ServiceQueue>
     */
    public function primaryQueues(): Collection
    {
        return $this->openQueues()
            ->filter(fn (ServiceQueue $queue) => ! $this->isFileCheckQueue($queue))
            ->values();
    }

    /**
     * Open file-check queues for the current shift.
     *
     * @return Collection<int, ServiceQueue>
     */
    public function fileCheckQueues(): Collection
    {
        return $this->openQueues()
            ->filter(fn (ServiceQueue $queue) => $this->isFileCheckQueue($queue))
            ->values();
    }

    /**
     * Whether the queue's service price is marked as file check.
     */
    public function isFileCheckQueue(ServiceQueue $queue): bool
    {
        return $this->servicePriceForQueue($queue)?->is_file_check ?? false;
    }

    /**
     * Get the configured TV layout for a queue.
     */
    public function displayLayout(ServiceQueue $queue): TokenDisplayLayout
    {
        return $this->servicePriceForQueue($queue)?->display_layout ?? TokenDisplayLayout::Board;
    }

    /**
     * Determine whether a queue uses the single-token TV layout.
     */
    public function isSingleTokenQueue(ServiceQueue $queue): bool
    {
        return $this->displayLayout($queue) === TokenDisplayLayout::SingleToken;
    }

    /**
     * Determine whether the TV may manually control the queue.
     *
     * Queues that follow the doctor are advanced from the medication page instead.
     */
    public function allowsManualTokenControls(ServiceQueue $queue): bool
    {
        return ! $this->followsDoctorToken($queue);
    }

    /**
     * Determine whether the doctor advances this queue's displayed token.
     */
    public function followsDoctorToken(ServiceQueue $queue): bool
    {
        return (bool) $queue->service?->follows_doctor_token;
    }

    /**
     * Move an arrived waiting token to serving without affecting other serving tokens.
     */
    public function startServing(QueueToken $token): ?QueueToken
    {
        return DB::transaction(function () use ($token) {
            $locked = QueueToken::query()
                ->whereKey($token->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== 'waiting' || $locked->arrived_at === null) {
                return null;
            }

            $locked->update([
                'status' => 'serving',
                'displayed_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Mark a serving token as served.
     */
    public function markServed(QueueToken $token): ?QueueToken
    {
        return DB::transaction(function () use ($token) {
            $locked = QueueToken::query()
                ->whereKey($token->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== 'serving') {
                return null;
            }

            $locked->update(['status' => 'served']);

            return $locked->fresh();
        });
    }

    /**
     * Mark the current token as served and call the next one in lane order.
     *
     * Leftover tokens from the previous shift's queue come before the new shift's token 1.
     */
    public function callNext(ServiceQueue $queue): ?QueueToken
    {
        return DB::transaction(function () use ($queue) {
            $lane = $this->laneQueues($queue);
            $current = $this->displayedTokenQuery($lane->modelKeys())
                ->lockForUpdate()
                ->first();

            if ($current?->status === 'serving') {
                $current->update(['status' => 'served']);
            } elseif ($current?->status === 'reserved') {
                $current->update(['displayed_at' => null]);
            }

            return $this->callNextToken($lane, $current);
        });
    }

    /**
     * Mark the current serving token as skipped and call the next one.
     */
    public function skipCurrent(ServiceQueue $queue): ?QueueToken
    {
        return DB::transaction(function () use ($queue) {
            $lane = $this->laneQueues($queue);
            $current = QueueToken::whereIn('service_queue_id', $lane->modelKeys())
                ->where('status', 'serving')
                ->orderByDesc('displayed_at')
                ->lockForUpdate()
                ->first();

            if ($current !== null) {
                $current->update(['status' => 'skipped']);
            }

            return $this->callNextToken($lane, $current);
        });
    }

    /**
     * Restore the current token and call the one before it in lane order.
     *
     * From the first token of a new shift's queue, this steps back to the previous queue's last token.
     */
    public function callPrevious(ServiceQueue $queue): ?QueueToken
    {
        return DB::transaction(function () use ($queue) {
            $lane = $this->laneQueues($queue);
            $current = $this->displayedTokenQuery($lane->modelKeys())
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return null;
            }

            $this->restoreToken($current);

            $previous = QueueToken::where('service_queue_id', $current->service_queue_id)
                ->where('token_number', $current->token_number - 1)
                ->lockForUpdate()
                ->first();

            if ($previous === null) {
                $laneIndex = $lane->search(fn (ServiceQueue $laneQueue): bool => $laneQueue->id === $current->service_queue_id);
                $previousQueue = is_int($laneIndex) && $laneIndex > 0 ? $lane->get($laneIndex - 1) : null;

                $previous = $previousQueue === null ? null : QueueToken::where('service_queue_id', $previousQueue->id)
                    ->orderByDesc('token_number')
                    ->lockForUpdate()
                    ->first();
            }

            if ($previous === null) {
                return null;
            }

            $previous->update($this->displayAttributes($previous));

            return $previous->fresh();
        });
    }

    /**
     * Restore the current token and display the token with the given number.
     *
     * The number is looked up in the current token's queue first, then the rest of the lane, newest first.
     * Returns null without changing anything when the number does not exist or was already served.
     */
    public function callTokenNumber(ServiceQueue $queue, int $tokenNumber): ?QueueToken
    {
        return DB::transaction(function () use ($queue, $tokenNumber) {
            $lane = $this->laneQueues($queue);
            $current = $this->displayedTokenQuery($lane->modelKeys())
                ->lockForUpdate()
                ->first();

            $searchOrder = $lane->reverse()->modelKeys();

            if ($current !== null) {
                $searchOrder = array_values(array_unique([$current->service_queue_id, ...$searchOrder]));
            }

            $candidates = QueueToken::whereIn('service_queue_id', $searchOrder)
                ->where('token_number', $tokenNumber)
                ->whereIn('status', ['waiting', 'reserved', 'serving'])
                ->lockForUpdate()
                ->get();

            $target = collect($searchOrder)
                ->map(fn (int $queueId): ?QueueToken => $candidates->firstWhere('service_queue_id', $queueId))
                ->filter()
                ->first();

            if ($target === null) {
                return null;
            }

            if ($current !== null && $current->isNot($target)) {
                $this->restoreToken($current);
            }

            $target->update($this->displayAttributes($target));

            return $target->fresh();
        });
    }

    /**
     * Find the first waiting or reserved token after the current one in lane order and display it.
     *
     * @param  Collection<int, ServiceQueue>  $lane
     */
    private function callNextToken(Collection $lane, ?QueueToken $current): ?QueueToken
    {
        $laneOrder = array_flip($lane->modelKeys());
        $currentPosition = $current === null
            ? [-1, 0]
            : [$laneOrder[$current->service_queue_id] ?? -1, $current->token_number];

        $next = QueueToken::whereIn('service_queue_id', $lane->modelKeys())
            ->whereIn('status', ['waiting', 'reserved'])
            ->lockForUpdate()
            ->get()
            ->filter(fn (QueueToken $token): bool => [$laneOrder[$token->service_queue_id], $token->token_number] > $currentPosition)
            ->sortBy([
                fn (QueueToken $a, QueueToken $b): int => $laneOrder[$a->service_queue_id] <=> $laneOrder[$b->service_queue_id],
                fn (QueueToken $a, QueueToken $b): int => $a->token_number <=> $b->token_number,
            ])
            ->first();

        if ($next === null) {
            return null;
        }

        $next->update($this->displayAttributes($next));

        return $next->fresh();
    }

    /**
     * Take a displayed token off the screen and put it back in line.
     */
    private function restoreToken(QueueToken $token): void
    {
        if ($token->status === 'serving') {
            $token->update([
                'status' => $token->arrived_at !== null ? 'waiting' : 'reserved',
            ]);

            return;
        }

        $token->update(['displayed_at' => null]);
    }

    /**
     * Tokens currently on screen for the given queues, latest first.
     *
     * @param  list<int|string>  $queueIds
     * @return Builder<QueueToken>
     */
    private function displayedTokenQuery(array $queueIds): Builder
    {
        return QueueToken::query()
            ->whereIn('service_queue_id', $queueIds)
            ->where(function (Builder $query): void {
                $query->where('status', 'serving')
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', 'reserved')
                            ->whereNotNull('displayed_at');
                    });
            })
            ->orderByDesc('displayed_at')
            ->orderByDesc('id');
    }

    /**
     * Get the attributes used when displaying a token.
     *
     * Unarrived reservations remain reserved while still appearing on the TV.
     *
     * @return array{status?: string, displayed_at: Carbon}
     */
    private function displayAttributes(QueueToken $token): array
    {
        if ($token->status === 'reserved') {
            return ['displayed_at' => now()];
        }

        return [
            'status' => 'serving',
            'displayed_at' => now(),
        ];
    }

    /**
     * Tokens for the given queue IDs and status.
     *
     * @param  list<int|string>  $queueIds
     * @return Collection<int, QueueToken>
     */
    private function tokensForQueues(array $queueIds, string $status): Collection
    {
        if ($queueIds === []) {
            return new Collection;
        }

        return QueueToken::query()
            ->with(['patient', 'invoiceItem.invoice.patient'])
            ->whereIn('service_queue_id', $queueIds)
            ->where('status', $status)
            ->when($status === 'waiting', function (Builder $query) {
                $query->whereNotNull('arrived_at');
            })
            ->orderBy('token_number')
            ->get();
    }

    /**
     * Get the service price attached to the queue's service and doctor.
     */
    private function servicePriceForQueue(ServiceQueue $queue): ?ServicePrice
    {
        return ServicePrice::query()
            ->where('service_id', $queue->service_id)
            ->where('doctor_id', $queue->doctor_id)
            ->first();
    }
}
