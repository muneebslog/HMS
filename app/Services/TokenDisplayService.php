<?php

namespace App\Services;

use App\Models\QueueToken;
use App\Models\ServiceQueue;
use Illuminate\Support\Facades\DB;

class TokenDisplayService
{
    /**
     * Get the token currently being served for the queue.
     */
    public function currentToken(ServiceQueue $queue): ?QueueToken
    {
        return $queue->tokens()
            ->where('status', 'serving')
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Mark the current serving token as served and call the next token by number.
     */
    public function callNext(ServiceQueue $queue): ?QueueToken
    {
        return DB::transaction(function () use ($queue) {
            $current = QueueToken::where('service_queue_id', $queue->id)
                ->where('status', 'serving')
                ->lockForUpdate()
                ->first();

            $currentNumber = $current?->token_number ?? 0;

            if ($current !== null) {
                $current->update(['status' => 'served']);
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
                ->where('status', 'serving')
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return null;
            }

            $current->update([
                'status' => $current->arrived_at !== null ? 'waiting' : 'reserved',
            ]);

            $previous = QueueToken::where('service_queue_id', $queue->id)
                ->where('token_number', $current->token_number - 1)
                ->lockForUpdate()
                ->first();

            if ($previous === null) {
                return null;
            }

            $previous->update(['status' => 'serving']);

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

        $next->update(['status' => 'serving']);

        return $next->fresh();
    }
}
