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
     * Find the oldest waiting token and mark it as serving.
     */
    private function callOldestWaiting(ServiceQueue $queue): ?QueueToken
    {
        $next = QueueToken::where('service_queue_id', $queue->id)
            ->where('status', 'waiting')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($next === null) {
            return null;
        }

        $next->update(['status' => 'serving']);

        return $next->fresh();
    }
}
