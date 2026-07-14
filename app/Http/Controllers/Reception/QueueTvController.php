<?php

namespace App\Http\Controllers\Reception;

use App\Enums\TokenResetType;
use App\Http\Controllers\Controller;
use App\Models\QueueToken;
use App\Models\ServiceQueue;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QueueTvController extends Controller
{
    /**
     * Show a TV-optimized read-only view of the reception queue.
     *
     * This view intentionally avoids Livewire, Flux, Tailwind CSS, and Vite so
     * it renders correctly on legacy Android TV browsers such as Chrome 93.
     */
    public function __invoke(Request $request): View
    {
        $currentShift = Shift::current();
        $selectedQueue = $this->resolveSelectedQueue($request, $currentShift);

        return view('pages.reception.queue-tv', [
            'currentShift' => $currentShift,
            'queues' => $this->queues($currentShift),
            'selectedQueue' => $selectedQueue,
            'tokens' => $selectedQueue !== null
                ? $this->tokens($selectedQueue)
                : new Collection,
        ]);
    }

    /**
     * Get all open service queues available for the current shift.
     *
     * @return Collection<int, ServiceQueue>
     */
    private function queues(?Shift $currentShift): Collection
    {
        if ($currentShift === null) {
            return new Collection;
        }

        $shiftDate = $currentShift->opened_at->toDateString();

        return ServiceQueue::with(['service', 'doctor'])
            ->withCount('tokens')
            ->where('status', 'open')
            ->where(function ($query) use ($currentShift, $shiftDate): void {
                $query->where('shift_id', $currentShift->id)
                    ->orWhere(function ($q) use ($shiftDate): void {
                        $q->where('reset_type', TokenResetType::Daily->value)
                            ->whereDate('date', $shiftDate);
                    });
            })
            ->orderBy('opened_at')
            ->get();
    }

    /**
     * Resolve the queue selected by the request.
     */
    private function resolveSelectedQueue(Request $request, ?Shift $currentShift): ?ServiceQueue
    {
        $queueId = $request->input('queue');

        if ($queueId === null || $currentShift === null) {
            return null;
        }

        return ServiceQueue::with(['service', 'doctor', 'tokens.invoiceItem.invoice.patient'])
            ->where('status', 'open')
            ->find($queueId);
    }

    /**
     * Get the tokens for the selected queue.
     *
     * @return Collection<int, QueueToken>
     */
    private function tokens(ServiceQueue $queue): Collection
    {
        return $queue->tokens
            ->sortBy('token_number')
            ->values();
    }
}
