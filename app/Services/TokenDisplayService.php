<?php

namespace App\Services;

use App\Models\QueueToken;
use App\Models\ServiceQueue;
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
}
