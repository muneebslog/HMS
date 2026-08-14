<?php

namespace App\Services;

use App\Enums\TokenDisplayLayout;
use App\Models\QueueToken;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TokenDisplayService
{
    /**
     * Get the token currently being served for the queue.
     */
    public function currentToken(ServiceQueue $queue): ?QueueToken
    {
        return $queue->tokens()
            ->with('patient')
            ->where(function ($query) {
                $query->where('status', 'serving')
                    ->orWhere(function ($query) {
                        $query->where('status', 'reserved')
                            ->whereNotNull('displayed_at');
                    });
            })
            ->orderByDesc('displayed_at')
            ->first();
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
     * Arrived waiting tokens across today's open file-check queues.
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
     * Serving tokens across today's open file-check queues.
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
     * Open non-file-check queues for today (primary board picker).
     *
     * @return Collection<int, ServiceQueue>
     */
    public function primaryQueues(): Collection
    {
        return $this->openQueuesToday()
            ->filter(fn (ServiceQueue $queue) => ! $this->isFileCheckQueue($queue))
            ->values();
    }

    /**
     * Open file-check queues for today.
     *
     * @return Collection<int, ServiceQueue>
     */
    public function fileCheckQueues(): Collection
    {
        return $this->openQueuesToday()
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
     * Mark the current serving token as served and call the next token by number.
     */
    public function callNext(ServiceQueue $queue): ?QueueToken
    {
        return DB::transaction(function () use ($queue) {
            $current = QueueToken::where('service_queue_id', $queue->id)
                ->where(function ($query) {
                    $query->where('status', 'serving')
                        ->orWhere(function ($query) {
                            $query->where('status', 'reserved')
                                ->whereNotNull('displayed_at');
                        });
                })
                ->orderByDesc('displayed_at')
                ->lockForUpdate()
                ->first();

            $currentNumber = $current?->token_number ?? 0;

            if ($current?->status === 'serving') {
                $current->update(['status' => 'served']);
            } elseif ($current?->status === 'reserved') {
                $current->update(['displayed_at' => null]);
            }

            return $this->callNextToken($queue, $currentNumber);
        });
    }

    /**
     * Mark the current serving token as served and call the next one.
     */
    public function skipCurrent(ServiceQueue $queue): ?QueueToken
    {
        return DB::transaction(function () use ($queue) {
            $current = QueueToken::where('service_queue_id', $queue->id)
                ->where('status', 'serving')
                ->lockForUpdate()
                ->first();

            $currentNumber = $current?->token_number ?? 0;

            if ($current !== null) {
                $current->update(['status' => 'skipped']);
            }

            return $this->callNextToken($queue, $currentNumber);
        });
    }

    /**
     * Restore the current serving token and call the previous token by number.
     */
    public function callPrevious(ServiceQueue $queue): ?QueueToken
    {
        return DB::transaction(function () use ($queue) {
            $current = QueueToken::where('service_queue_id', $queue->id)
                ->where(function ($query) {
                    $query->where('status', 'serving')
                        ->orWhere(function ($query) {
                            $query->where('status', 'reserved')
                                ->whereNotNull('displayed_at');
                        });
                })
                ->orderByDesc('displayed_at')
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return null;
            }

            if ($current->status === 'serving') {
                $current->update([
                    'status' => $current->arrived_at !== null ? 'waiting' : 'reserved',
                ]);
            } else {
                $current->update(['displayed_at' => null]);
            }

            $previous = QueueToken::where('service_queue_id', $queue->id)
                ->where('token_number', $current->token_number - 1)
                ->lockForUpdate()
                ->first();

            if ($previous === null) {
                return null;
            }

            $previous->update($this->displayAttributes($previous));

            return $previous->fresh();
        });
    }

    /**
     * Find the next waiting or reserved token after the given number and mark it as serving.
     */
    private function callNextToken(ServiceQueue $queue, int $currentNumber): ?QueueToken
    {
        $next = QueueToken::where('service_queue_id', $queue->id)
            ->whereIn('status', ['waiting', 'reserved'])
            ->where('token_number', '>', $currentNumber)
            ->orderBy('token_number')
            ->lockForUpdate()
            ->first();

        if ($next === null) {
            return null;
        }

        $next->update($this->displayAttributes($next));

        return $next->fresh();
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
     * Open service queues for today with service and doctor relations.
     *
     * @return Collection<int, ServiceQueue>
     */
    private function openQueuesToday(): Collection
    {
        return ServiceQueue::with(['service', 'doctor'])
            ->where('status', 'open')
            ->whereDate('date', Carbon::today())
            ->orderBy('opened_at')
            ->get();
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
