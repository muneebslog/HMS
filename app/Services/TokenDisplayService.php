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
     * Mark the current serving token as served and call the next waiting token.
     */
    public function callNext(ServiceQueue $queue): ?QueueToken
    {
        return DB::transaction(function () use ($queue) {
            $this->resolveCurrentToken($queue, 'served');

            return $this->callOldestWaiting($queue);
        });
    }

    /**
     * Mark the current serving token as skipped and call the next waiting token.
     */
    public function skipCurrent(ServiceQueue $queue): ?QueueToken
    {
        return DB::transaction(function () use ($queue) {
            $this->resolveCurrentToken($queue, 'skipped');

            return $this->callOldestWaiting($queue);
        });
    }

    /**
     * Mark the current serving token as waiting and call the previous token.
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

            $previous = QueueToken::where('service_queue_id', $queue->id)
                ->where('token_number', $current->token_number - 1)
                ->lockForUpdate()
                ->first();

            if ($previous === null) {
                return null;
            }

            $current->update(['status' => 'waiting']);
            $previous->update(['status' => 'serving']);

            return $previous->fresh();
        });
    }

    /**
     * Resolve the current serving token with the given status.
     */
    private function resolveCurrentToken(ServiceQueue $queue, string $status): void
    {
        $current = QueueToken::where('service_queue_id', $queue->id)
            ->where('status', 'serving')
            ->lockForUpdate()
            ->first();

        if ($current !== null) {
            $current->update(['status' => $status]);
        }
    }

    /**
     * Find the next waiting token by token number and mark it as serving.
     */
    private function callOldestWaiting(ServiceQueue $queue): ?QueueToken
    {
        $next = QueueToken::where('service_queue_id', $queue->id)
            ->where('status', 'waiting')
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
